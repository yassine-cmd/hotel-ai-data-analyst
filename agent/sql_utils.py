"""SQL validation: parse, validate SELECT-only queries, and build property
projects from LIMIT clauses."""

import sqlglot
import sqlglot.errors
from sqlglot import exp
from typing import List, Optional, Set, Tuple

from .tools._helpers import _audit_logger

UNSAFE_STATEMENT_TYPES = (
    exp.Insert, exp.Update, exp.Delete, exp.Drop,
    exp.Alter, exp.Create, exp.TruncateTable, exp.Merge, exp.Command,
)

BLOCKED_SCHEMAS = {"information_schema", "mysql", "sys", "performance_schema"}


def validate_sql(sql: str, tree: Optional[exp.Expression] = None, sensitive_tables: Optional[Set[str]] = None) -> Tuple[bool, List[str]]:
    sql = sql.strip()
    if not sql:
        return False, ["EMPTY_QUERY"]
    violations: List[str] = []
    if sensitive_tables is None:
        sensitive_tables = set()

    try:
        if tree is None:
            tree = sqlglot.parse(sql, dialect="mysql")
        if isinstance(tree, exp.Expression):
            statements = [tree]
        else:
            statements = tree
        if not statements:
            return False, ["EMPTY_QUERY"]
        real_statements = [s for s in statements if s is not None]
        if len(real_statements) > 1:
            return False, ["MULTI_STATEMENT_BLOCKED"]
        for tree in statements:
            if tree is None:
                continue
            if isinstance(tree, UNSAFE_STATEMENT_TYPES):
                violations.append(type(tree).__name__)
            for node in tree.walk():
                if isinstance(node, UNSAFE_STATEMENT_TYPES) and node is not tree:
                    violations.append(type(node).__name__)
                if isinstance(node, exp.Table):
                    db = (node.db or "").lower()
                    catalog = (node.catalog or "").lower()
                    table_name = (node.name or "").lower()
                    if db in BLOCKED_SCHEMAS or catalog in BLOCKED_SCHEMAS:
                        violations.append(f"METADATA_BLOCKED: Access to '{db or catalog}.{table_name}' is blocked. The full schema is provided in the system prompt — use table and column names directly.")
                    # Block sensitive tables inside subqueries/CTEs
                    if sensitive_tables and table_name in sensitive_tables:
                        parent = node.parent
                        in_subquery = False
                        while parent:
                            if isinstance(parent, (exp.Subquery, exp.CTE)):
                                in_subquery = True
                                break
                            if isinstance(parent, exp.Select) and parent.parent is None:
                                break
                            parent = parent.parent
                        if in_subquery:
                            violations.append(f"SENSITIVE_TABLE_IN_SUBQUERY: Table '{table_name}' inside a subquery/CTE is blocked to prevent column aliasing bypass.")
                if node.args.get("into"):
                    violations.append("INTO_OUTFILE_BLOCKED: SELECT INTO OUTFILE/DUMPFILE is not allowed.")
    except sqlglot.errors.ParseError as e:
        violations.append(f"PARSE_ERROR: {e}")
    if violations:
        _audit_logger.warning("SQL_VALIDATION_BLOCKED | sql=%.200s | reasons=%s", sql, "; ".join(dict.fromkeys(violations)))
        # Surface blocked SQL (injection/prompt-injection attempts) to the
        # admin's system-audit log. No client_id here — validation runs before
        # any session context is established.
        try:
            from agent.audit_forwarder import submit_event
            submit_event("security.sql_blocked", level="warning", context={"reasons": list(dict.fromkeys(violations))})
        except Exception:
            pass
        return False, list(dict.fromkeys(violations))
    return True, []
