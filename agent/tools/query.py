"""SQL query tool: parse, validate, execute SELECT queries against the remote
data plane, and profile results."""

import json
import logging
import time
from typing import Any, Dict, List, Optional

import pandas as pd
from sqlglot import exp

from ._helpers import _parse_sql_safely, _top_level_tables
from .base import BaseTool, StandardToolResult, build_envelope
from ..db import BudgetExhausted, profile_query
from ..sql_utils import validate_sql

logger = logging.getLogger(__name__)

_STRINGY_COL_TOKENS = {"zip", "postal", "phone", "tel", "fax", "code", "id", "sku", "isbn", "account", "card"}


def _result_to_dataframe(result) -> pd.DataFrame:
    col_names = [c.name for c in result.columns]
    rows = []
    for r in result.rows:
        rows.append(dict(zip(col_names, r)))
    df = pd.DataFrame(rows, columns=col_names)
    _NUMERIC_TYPES = {"BIGINT", "INT", "SMALLINT", "TINYINT", "MEDIUMINT", "BIT"}
    _DECIMAL_TYPES = {"DECIMAL", "NUMERIC", "FLOAT", "DOUBLE", "REAL"}
    _TEMPORAL_TYPES = {"DATETIME", "TIMESTAMP", "DATE", "TIME", "YEAR"}
    for col_meta_item in result.columns:
        db_type = col_meta_item.db_type.upper() if col_meta_item.db_type else ""
        cname = col_meta_item.name
        tokens = set(cname.lower().replace("-", "_").split("_"))
        if tokens & _STRINGY_COL_TOKENS:
            continue
        if db_type in _NUMERIC_TYPES:
            try:
                df[cname] = pd.to_numeric(df[cname], errors='coerce').astype("Int64")
            except Exception:
                pass
        elif db_type in _DECIMAL_TYPES:
            try:
                df[cname] = pd.to_numeric(df[cname], errors='coerce')
            except Exception:
                pass
        elif db_type in _TEMPORAL_TYPES:
            try:
                df[cname] = pd.to_datetime(df[cname], errors='coerce')
            except Exception:
                pass
    return df


class SQLTool(BaseTool):
    name = "execute_sql"
    description = "Execute SQL SELECT queries. Each result becomes a named DataFrame. Returns accurate metadata (true row count, columns, dtypes, null counts, value distributions, numeric/temporal stats) computed over the FULL result set via SQL aggregates. When the row budget truncates the materialized DataFrame, the result honestly reports 'truncated:true' with 'true_row_count' and 'materialized_rows' so you never mistake a sample for the full set. Pair with run_python in the same call to analyze results."
    input_schema = {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "description": "REQUIRED. One sentence describing what this call does.",
            },
            "queries": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "sql": {"type": "string", "description": "SQL SELECT query to execute."},
                        "df_name": {"type": "string", "description": "Variable name for the result DataFrame in Python."},
                    },
                    "required": ["sql", "df_name"],
                },
                "description": "List of SQL SELECT queries. Each result becomes a DataFrame in the sandbox by df_name.",
            },
        },
        "required": ["action", "queries"],
    }

    @staticmethod
    def _build_queries(inputs: Dict[str, Any]) -> List[Dict[str, str]]:
        queries = inputs.get("queries", [])
        if isinstance(queries, str):
            try:
                queries = json.loads(queries)
            except json.JSONDecodeError:
                logger.warning("queries field is a string but not valid JSON: %s", queries[:200])
                return []
        if not queries:
            return []
        if isinstance(queries, dict):
            queries = [queries]
        built: List[Dict[str, str]] = []
        for q in queries:
            sql = q.get("sql")
            df_name = q.get("df_name")
            if not sql or not df_name:
                continue
            if not isinstance(df_name, str) or not df_name.isidentifier():
                logger.warning("Skipping query with invalid df_name (not a valid Python identifier): %r", df_name)
                continue
            built.append({"sql": sql, "df_name": df_name})
        return built

    @staticmethod
    def _validate_result(sql: str, df: pd.DataFrame, schema_dict: Dict[str, Any], tree: Optional[exp.Expression] = None) -> List[str]:
        warnings: List[str] = []
        rows, cols = df.shape
        if tree is None:
            tree = _parse_sql_safely(sql)
        if not tree:
            return warnings
        for col in df.columns:
            if col.startswith("id_") or col == "id":
                if rows > 0 and df[col].isna().all():
                    warnings.append(f"\u26a0 Column '{col}' is entirely NULL (all {rows} rows). If this is a join key, the JOIN may be silently dropping rows.")
        tables = _top_level_tables(tree)
        if rows == 0 and len(tables) >= 2:
            names = ", ".join(t.name for t in tables[:3] if t.name)
            warnings.append(f"\u26a0 JOIN returned 0 rows ({names}...). Verify join keys before concluding data doesn't exist.")
        has_groupby = bool(tree.find(exp.Group))
        if len(tables) >= 2:
            present_all: set = set()
            fk_unknown = False
            table_names = []
            for t in tables:
                tname = (t.name or "").lower()
                if tname and tname != "dual":
                    table_names.append(tname)
                    present_all.add(tname)
            child_links = []
            for idx, tname in enumerate(table_names):
                if idx == 0:
                    continue
                info = schema_dict.get(tname)
                fks = (info or {}).get("foreign_keys") if info else None
                if fks is None:
                    fk_unknown = True
                    continue
                for fk in fks:
                    ref = (fk.get("ref_table") or "").lower()
                    if ref and ref in present_all and ref != tname:
                        child_links.append((tname, ref))
                        break
            counts = list(tree.find_all(exp.Count))
            non_distinct_count = any(not isinstance(c.this, exp.Distinct) for c in counts)
            other_aggs = [a for a in tree.find_all(exp.AggFunc) if not isinstance(a, exp.Count)]
            if child_links and (non_distinct_count or other_aggs):
                rels = ", ".join(f"{p} 1\u2192many {c}" for p, c in child_links)
                warnings.append(
                    f"\u26a0 Joining to child table(s) multiplies rows by their 1-to-many ratio: {rels}. "
                    "Aggregates (COUNT/SUM/AVG) are computed at the joined grain, not the parent grain, "
                    "so counts/sums are inflated. Use COUNT(DISTINCT <parent>.id) for parent-level counts, "
                    "or pre-aggregate child tables in a subquery before joining."
                )
            elif fk_unknown and (non_distinct_count or other_aggs):
                warnings.append(
                    "\u26a0 This query joins multiple tables and aggregates without DISTINCT. If any joined "
                    "table is a child (1-to-many), row counts multiply and COUNT/SUM/AVG are inflated. "
                    "Prefer COUNT(DISTINCT <parent>.id) or pre-aggregate child tables before joining."
                )
        if len(tables) >= 2 and not has_groupby:
            max_source = max((schema_dict.get((t.name or "").lower(), {}).get("row_count", 0) or 0) for t in tables if t.name)
            if max_source > 0 and rows > max_source * 2:
                warnings.append(f"\u26a0 JOIN fan-out: {rows} rows from source max {max_source}. SUM/AVG values likely inflated. Aggregate each table before joining.")
        return warnings

    @staticmethod
    def _enrich_unknown_column_error(sql: str, error_msg: str, schema_dict: Dict[str, Any]) -> str:
        tree = _parse_sql_safely(sql)
        if not tree:
            return error_msg
        from sqlglot import exp
        hints = []
        for table_node in tree.find_all(exp.Table):
            if table_node.name:
                t_name = table_node.name.lower()
                if t_name in schema_dict:
                    cnames = [c["name"] for c in schema_dict[t_name]["columns"]]
                    hint = f"  {t_name}: {', '.join(cnames[:15])}"
                    if len(cnames) > 15:
                        hint += f" ... (+{len(cnames)-15})"
                    hints.append(hint)
        if hints:
            return error_msg + "\nAvailable columns:\n" + "\n".join(hints)
        return error_msg

    async def run(self, inputs: Dict[str, Any], context: Dict[str, Any]) -> Any:
        queries = self._build_queries(inputs)
        if not queries:
            return StandardToolResult(
                status="infra_error",
                tool="execute_sql",
                summary="No queries provided",
                data=build_envelope("error", error="No queries provided. Pass at least one query in the 'queries' array.", error_kind="infra"),
                ui_data={},
            )

        executor = context.get("executor")
        session_dataframes = context.get("session_dataframes", {})
        session_id = context["session_id"]
        client_id = context["client_id"]
        datasource_id = context.get("datasource_id", "__default__")
        schema_dict = context.get("schema_dict", {})
        sensitive_tables = context.get("sensitive_tables", set())
        unauthorized_tables = context.get("unauthorized_tables", set())
        unauthorized_lower = {str(t).lower() for t in unauthorized_tables}
        budget = context.get("session_budget")
        memory = context.get("memory")
        user_ref = context.get("user_ref")
        execution_max_rows = context.get("execution_max_rows", 10000)
        execution_timeout_ms = context.get("execution_timeout_ms", 15000)

        query_results = []
        for q in queries:
            sql, df_name = q["sql"], q["df_name"]
            try:
                if budget is not None:
                    await budget.check()
                tree = _parse_sql_safely(sql)
                is_valid, violations = validate_sql(sql, tree=tree, sensitive_tables=sensitive_tables)
                if not is_valid:
                    raise ValueError("SQL rejected: " + "; ".join(violations))
                guard = context.get("guard")
                redacted_cols: List[str] = []
                alias_map = None
                if guard is not None:
                    alias_map = guard._build_alias_map(tree)
                    allowed, blocked = guard.check_access(sql, tree=tree, alias_map=alias_map)
                    if not allowed:
                        reasons = "; ".join(blocked)
                        is_sensitive_table_block = any("sensitive table" in r for r in blocked)
                        is_sensitive_column_block = any("sensitive column" in r for r in blocked)
                        if is_sensitive_table_block:
                            error_kind = "sensitive_table"
                            msg = (
                                f"Access blocked: {reasons}. "
                                f"This table is not queryable — do not retry queries referencing it."
                            )
                        elif is_sensitive_column_block:
                            error_kind = "sensitive_column"
                            msg = (
                                f"Access blocked: {reasons}. "
                                f"Remove blocked columns and use only accessible_columns."
                            )
                        else:
                            error_kind = "input"
                            msg = f"Access blocked: {reasons}"
                        query_results.append(build_envelope(
                            "error",
                            error=msg,
                            error_kind=error_kind,
                            df_name=df_name))
                        continue
                    safe_sql, redacted_cols = guard.rewrite_sql(sql, tree=tree, alias_map=alias_map)
                else:
                    safe_sql = sql

                t0 = time.perf_counter()
                max_rows = max(0, min(execution_max_rows, 10000))
                if budget is not None:
                    max_rows = min(max_rows, max(0, budget.row_limit - budget.rows_fetched))
                timeout_ms = min(execution_timeout_ms, max(5000, min(25000, int(budget.time_limit * 1000 - budget.time_used * 1000))) if budget else 15000)
                if tree is not None:
                    schema_lower = {str(k).lower() for k in schema_dict}
                    physical_tables = sorted({t.name.lower() for t in tree.find_all(exp.Table) if t.name and t.name.lower() in schema_lower})
                else:
                    physical_tables = []
                # Per-user permission allow-list (distinct from the global
                # sensitive guard above). Admin users have no unauthorized
                # tables, so this only fires for gestionnaires whose tokens
                # don't grant a referenced table. Sensitive tables are already
                # handled by the guard and take precedence.
                permission_denied = [t for t in physical_tables if t in unauthorized_lower]
                if permission_denied:
                    query_results.append(build_envelope(
                        "error",
                        error=(
                            f"Access blocked: table(s) {', '.join(sorted(permission_denied))} "
                            f"are not in this user's permission grants. Do not retry queries referencing them."
                        ),
                        error_kind="permission",
                        df_name=df_name))
                    continue
                result = await executor.execute(safe_sql, datasource_id=datasource_id, session_id=session_id, max_rows=max_rows, timeout_ms=timeout_ms, user_ref=user_ref, referenced_tables=physical_tables)
                df = _result_to_dataframe(result)
                elapsed_ms = (time.perf_counter() - t0) * 1000
                shape = list(df.shape)
                if budget is not None:
                    await budget.deduct(shape[0], elapsed_ms)
                pre_cols = set(df.columns)
                if guard is not None:
                    df = guard.strip_columns(df, sql, tree=tree, alias_map=alias_map)
                    stripped = [c for c in pre_cols if c not in set(df.columns)]
                    if stripped:
                        redacted_cols.extend(stripped)

                tables_referenced = sorted({t.name.lower() for t in tree.find_all(exp.Table) if t.name}) if tree else []

                try:
                    meta = await profile_query(executor, safe_sql, df, datasource_id=datasource_id, session_id=session_id, user_ref=user_ref, referenced_tables=physical_tables)
                except Exception:
                    meta = None
                if meta is None:
                    true_count = len(df)
                    df_capped = bool(max_rows is not None and len(df) >= max_rows)
                else:
                    true_count = meta["row_count"]
                    df_capped = bool(max_rows is not None and len(df) < true_count)

                if df_capped:
                    truncated_note = ("\n[Note: stats computed on the first rows only — the result "
                                     "was truncated by the row budget; counts are approximate.]")
                else:
                    truncated_note = ""

                if memory is not None and len(df) == 1 and df.shape[1] == 1 and not df_capped:
                    val = df.iloc[0, 0]
                    if pd.notna(val):
                        if hasattr(val, "item"):
                            val = val.item()
                        if isinstance(val, (int, float, str, bool)):
                            memory.verified_facts[f"{df_name}.{df.columns[0]}"] = val

                session_manager = context.get("session_manager")
                if session_manager:
                    await session_manager.store.put(client_id, session_id, df_name, df)
                df_meta = {
                    "shape": [true_count, len(df.columns)],
                    "columns": list(df.columns),
                    "dtypes": {col: str(dtype) for col, dtype in df.dtypes.items()},
                    "truncated": df_capped,
                }
                session_dataframes[df_name] = df_meta
                result_entry = {
                    "df_name": df_name,
                    "shape": df_meta["shape"],
                    "columns": df_meta["columns"],
                    "dtypes": df_meta["dtypes"],
                    "status": "success",
                    "tables": tables_referenced,
                    "true_row_count": true_count,
                    "materialized_rows": len(df),
                    "truncated": df_capped,
                    "truncated_note": truncated_note if truncated_note else None,
                }
                if redacted_cols:
                    result_entry["redacted_columns"] = redacted_cols
                warnings = self._validate_result(sql, df, schema_dict, tree=tree)
                if warnings:
                    result_entry["warnings"] = warnings
                query_results.append(result_entry)
            except BudgetExhausted as e:
                remaining = len(queries) - len(query_results) - 1
                msg = str(e)
                if remaining > 0:
                    msg += f" [BUDGET EXHAUSTED] ({remaining} remaining query(s) skipped)"
                query_results.append(build_envelope(
                    "error", error=msg, error_kind="budget", df_name=df_name, budget_summary=e.detail))
                break
            except Exception as e:
                msg = str(e)[:500]
                logger.warning("Query failed | df_name=%s | sql=%.100s | error=%s", df_name, sql, msg)
                if "Unknown column" in msg:
                    msg = self._enrich_unknown_column_error(sql, msg, schema_dict)
                query_results.append(build_envelope("error", error=msg, error_kind="sql", df_name=df_name))

        statuses = [r.get("status") for r in query_results]
        any_err = any(s == "error" for s in statuses)
        any_ok = any(s == "success" for s in statuses)
        if any_err and any_ok:
            status = "partial"
            partial_reason = "some_queries_failed"
        elif any_err:
            status = "error"
            partial_reason = None
        else:
            status = "success"
            partial_reason = None

        error_text: Optional[str] = None
        error_kind: Optional[str] = None
        if status in ("partial", "error"):
            errs = [r for r in query_results if r.get("status") == "error"]
            if any("budget_summary" in r for r in errs):
                error_kind = "budget"
            else:
                kinds = [r.get("error_kind") for r in errs if r.get("error_kind")]
                error_kind = kinds[0] if kinds else "sql"
            n_ok = sum(1 for s in statuses if s == "success")
            n_err = len(errs)
            first = errs[0].get("error") or errs[0].get("message", "")
            if status == "partial":
                error_text = f"{n_ok}/{len(query_results)} query(s) succeeded, {n_err} failed. First error: {first}"
            else:
                error_text = f"{n_err} query(s) failed. First error: {first}"

        all_warnings: List[str] = []
        for r in query_results:
            all_warnings.extend(r.get("warnings", []))

        llm_ctx = build_envelope(
            status,
            error=error_text,
            error_kind=error_kind,
            message=(f"\u26a0 {len(all_warnings)} warning(s) — investigate before proceeding" if all_warnings else None),
            partial_reason=partial_reason,
            results=query_results,
        )
        n_ok = sum(1 for s in statuses if s == "success")
        error_summary = error_text[:200] if error_text else ""
        return StandardToolResult(
            status="success" if status == "success" else "user_error",
            tool="execute_sql",
            summary=f"{n_ok}/{len(query_results)} queries executed" if status == "success" else error_summary,
            data=llm_ctx,
            ui_data={"results": query_results, "status": llm_ctx["status"], "error": llm_ctx.get("error"), "error_kind": llm_ctx.get("error_kind")},
            repair_hint=error_text.split(". ")[0] if error_text else None,
        )
