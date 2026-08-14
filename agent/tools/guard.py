"""Sensitive data guard: block/rewrite SQL queries to prevent access to
sensitive tables and columns."""

from typing import Any, Callable, Dict, List, Optional, Set, Tuple, Union

import pandas as pd
from sqlglot import exp

from ._helpers import _audit_logger, _parse_sql_safely


class SensitiveDataGuard:
    _UNRESOLVABLE = "__UNKNOWN__"

    def __init__(
        self,
        schema_provider: Callable[[], Dict[str, Any]],
        sensitive_tables: Union[List[str], Callable[[], Set[str]], None] = None,
        sensitive_columns: Union[Dict[str, List[str]], Callable[[], Dict[str, List[str]]], None] = None,
        allowed_columns: Union[Dict[str, List[str]], Callable[[], Dict[str, List[str]]], None] = None,
    ):
        self._schema_provider = schema_provider
        self._sensitive_tables_provider = sensitive_tables if callable(sensitive_tables) else (lambda: set(sensitive_tables or []))
        self._sensitive_columns_provider = sensitive_columns if callable(sensitive_columns) else (lambda: sensitive_columns or {})
        # Per-user PMS permission column allow-list: {table: [cols]}. Absent table
        # = unrestricted on columns. This is the *allow* axis, separate from the
        # global sensitive deny axis above; final visible columns are
        # allowed ∩ ¬sensitive (sensitive always wins).
        self._allowed_columns_provider = allowed_columns if callable(allowed_columns) else (lambda: allowed_columns or {})

    @property
    def _schema_dict(self) -> Dict[str, Any]:
        return self._schema_provider()

    @property
    def sensitive_tables(self) -> List[str]:
        return sorted(self._sensitive_tables_provider())

    def _normalize_table_name(self, table_name: Optional[str]) -> str:
        if not table_name:
            return ""
        name = str(table_name).strip().strip("`\"'")
        if "." in name:
            name = name.split(".")[-1]
        return name.lower()

    def is_sensitive_table(self, table_name: Optional[str]) -> bool:
        if not table_name:
            return False
        normalized = self._normalize_table_name(table_name)
        return any(
            self._normalize_table_name(t) == normalized
            for t in self._sensitive_tables_provider()
        )

    def accessible_columns(self, table_name: str) -> List[str]:
        schema = self._schema_dict
        if table_name not in schema:
            return []
        if self.is_sensitive_table(table_name):
            return []
        all_cols = [c["name"] for c in schema[table_name].get("columns", [])]
        sc = self._sensitive_columns_provider()
        wildcards = set(sc.get("*", []))
        table_blocked = set(sc.get(table_name, []))
        blocked = {c.lower() for c in wildcards} | {c.lower() for c in table_blocked}
        allowed = self._allowed_cols(table_name)
        if allowed is None:
            return [c for c in all_cols if c.lower() not in blocked]
        return [c for c in all_cols if c.lower() in allowed and c.lower() not in blocked]

    def _build_alias_map(self, tree: exp.Expression) -> Dict[str, str]:
        alias_map: Dict[str, str] = {}
        for table in tree.find_all(exp.Table):
            alias = table.alias_or_name
            if alias:
                alias_map[self._normalize_table_name(alias)] = self._normalize_table_name(table.name)
        return alias_map

    def _resolve_table(self, col: exp.Column, alias_map: Dict[str, str]) -> str:
        table = col.table
        if table:
            tbl = self._normalize_table_name(table)
            return alias_map.get(tbl, tbl)
        return self._UNRESOLVABLE

    def _is_sensitive_col(self, col_name: str, table_name: Optional[str]) -> bool:
        sc = self._sensitive_columns_provider()
        global_cols = sc.get("*", [])
        if col_name.lower() in {c.lower() for c in global_cols}:
            return True
        if table_name:
            normalized = self._normalize_table_name(table_name)
            table_cols = sc.get(normalized, [])
            if col_name.lower() in {c.lower() for c in table_cols}:
                return True
        return False

    def _allowed_cols(self, table_name: Optional[str]) -> Optional[Set[str]]:
        """Return the allow-set of column names for a table, or None when the
        table is unrestricted (no entry in the allow-list)."""
        ac = self._allowed_columns_provider()
        if not ac:
            return None
        normalized = self._normalize_table_name(table_name)
        val = ac.get(normalized)
        if val is None:
            return None
        if isinstance(val, str) and val == "*":
            return None
        return {str(c).lower() for c in val}

    def _is_allowed_col(self, col_name: str, table_name: Optional[str]) -> bool:
        allowed = self._allowed_cols(table_name)
        if allowed is None:
            return True
        return col_name.lower() in allowed

    def _resolve_unqualified_column(self, col_name: str, tree: exp.Expression) -> Optional[str]:
        candidates = set()
        for table in tree.find_all(exp.Table):
            if not table.name:
                continue
            tname = self._normalize_table_name(table.name)
            schema = self._schema_dict.get(tname)
            if schema is None:
                continue
            for col in schema.get("columns", []):
                if col.get("name") == col_name:
                    candidates.add(tname)
                    break
        if len(candidates) == 1:
            return next(iter(candidates))
        return None

    def check_access(self, sql: str, tree: Optional[exp.Expression] = None, alias_map: Optional[Dict[str, str]] = None) -> Tuple[bool, List[str]]:
        if tree is None:
            tree = _parse_sql_safely(sql)
        if tree is None:
            return False, ["SQL parse failed"]

        if alias_map is None:
            alias_map = self._build_alias_map(tree)
        blocked: List[str] = []

        for table in tree.find_all(exp.Table):
            if table.name and self.is_sensitive_table(table.name):
                blocked.append(f"sensitive table '{table.name}'")

        if blocked:
            _audit_logger.warning("BLOCKED | sql=%.200s | reasons=%s", sql, "; ".join(blocked))
            # Attempted access to a sensitive table/column — forward to the
            # admin's system-audit log as a security signal. No client_id here
            # (runs inside the agent loop before it is plumbed through).
            try:
                from agent.audit_forwarder import submit_event
                submit_event("security.sql_blocked", level="warning", context={"reasons": list(dict.fromkeys(blocked))})
            except Exception:
                pass
            return False, list(dict.fromkeys(blocked))

        select_aliases: Set[str] = set()
        for select in tree.find_all(exp.Select):
            for expr in select.expressions:
                if isinstance(expr, exp.Alias):
                    select_aliases.add(expr.alias.lower())

        for col in tree.find_all(exp.Column):
            if col.name and col.name.lower() in select_aliases and not col.table:
                continue
            resolved = self._resolve_table(col, alias_map)
            if resolved == self._UNRESOLVABLE:
                resolved = self._resolve_unqualified_column(col.name, tree)
            if resolved is None or resolved not in self._schema_dict:
                blocked.append(f"unresolvable column '{col.name}' (subquery/CTE alias or USING join)")
                continue
            if self._is_sensitive_col(col.name, resolved):
                blocked.append(f"sensitive column '{resolved}.{col.name}'")
                continue
            if not self._is_allowed_col(col.name, resolved):
                blocked.append(f"restricted column '{resolved}.{col.name}' (not granted by permission token)")

        return (False, list(dict.fromkeys(blocked))) if blocked else (True, [])

    def rewrite_sql(self, sql: str, tree: Optional[exp.Expression] = None, alias_map: Optional[Dict[str, str]] = None) -> Tuple[str, List[str]]:
        if tree is None:
            tree = _parse_sql_safely(sql)
        if tree is None:
            return sql, []

        if alias_map is None:
            alias_map = self._build_alias_map(tree)

        for select in tree.find_all(exp.Select):
            new_exprs = []
            for expr in select.expressions:
                if isinstance(expr, exp.Star):
                    from_clause = select.find(exp.From)
                    if from_clause:
                        expanded = []
                        for t in from_clause.find_all(exp.Table):
                            parent_node = t.parent
                            in_sub = False
                            while parent_node is not None and parent_node is not from_clause:
                                if isinstance(parent_node, (exp.Subquery, exp.CTE)):
                                    in_sub = True
                                    break
                                parent_node = parent_node.parent
                            if in_sub:
                                continue
                            if t.name and t.name.lower() in self._schema_dict:
                                for c in self._schema_dict[t.name.lower()].get("columns", []):
                                    if not self._is_sensitive_col(c["name"], t.name.lower()) and self._is_allowed_col(c["name"], t.name.lower()):
                                        expanded.append(exp.Column(this=exp.to_identifier(c["name"])))
                        if expanded:
                            new_exprs.extend(expanded)
                        else:
                            new_exprs.append(expr)
                    else:
                        new_exprs.append(expr)
                else:
                    new_exprs.append(expr)
            select.set("expressions", new_exprs)

        redacted: List[str] = []

        def _redact(node):
            if isinstance(node, exp.Column):
                resolved = self._resolve_table(node, alias_map)
                if resolved == self._UNRESOLVABLE:
                    resolved = self._resolve_unqualified_column(node.name, tree)
                sensitive = resolved != self._UNRESOLVABLE and self._is_sensitive_col(node.name, resolved)
                allowed = self._is_allowed_col(node.name, resolved)
                if resolved == self._UNRESOLVABLE or sensitive or not allowed:
                    label = f"{resolved}.{node.name}" if resolved and resolved != self._UNRESOLVABLE else node.name
                    redacted.append(label)
                    return exp.Literal.string("[SENSITIVE]" if sensitive else "[RESTRICTED]")
            return node

        tree = tree.transform(_redact)
        return tree.sql(dialect="mysql"), list(dict.fromkeys(redacted))

    def strip_columns(self, df: pd.DataFrame, sql: str, tree: Optional[exp.Expression] = None, alias_map: Optional[Dict[str, str]] = None) -> pd.DataFrame:
        if tree is None:
            tree = _parse_sql_safely(sql)
        if tree is None:
            return df

        if alias_map is None:
            alias_map = self._build_alias_map(tree)
        cols_to_drop: List[str] = []

        alias_to_source: Dict[str, Tuple[str, str]] = {}
        for select in tree.find_all(exp.Select):
            for expr in select.expressions:
                if isinstance(expr, exp.Alias) and isinstance(expr.this, exp.Column):
                    alias_name = expr.alias
                    resolved = self._resolve_table(expr.this, alias_map)
                    alias_to_source[alias_name] = (resolved, expr.this.name)

        for col_name in df.columns:
            if self._is_sensitive_col(col_name, None):
                cols_to_drop.append(col_name)
                continue
            for table_name in alias_map.values():
                if self._is_sensitive_col(col_name, table_name):
                    cols_to_drop.append(col_name)
                    break
                if not self._is_allowed_col(col_name, table_name):
                    cols_to_drop.append(col_name)
                    break
            if col_name in cols_to_drop:
                continue
            if col_name in alias_to_source:
                resolved, source_col = alias_to_source[col_name]
                if resolved != self._UNRESOLVABLE and self._is_sensitive_col(source_col, resolved):
                    cols_to_drop.append(col_name)
                elif resolved != self._UNRESOLVABLE and not self._is_allowed_col(source_col, resolved):
                    cols_to_drop.append(col_name)
                elif resolved == self._UNRESOLVABLE:
                    cols_to_drop.append(col_name)

        if cols_to_drop:
            _audit_logger.warning("STRIPPED | sql=%.200s | columns=%s", sql, ", ".join(cols_to_drop))
            df = df.drop(columns=[c for c in cols_to_drop if c in df.columns])
        return df
