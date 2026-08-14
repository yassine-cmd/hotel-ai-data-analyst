"""Data-plane abstraction: QueryExecutor protocol, RemoteQueryExecutor (HTTP),
budget tracking, DDL formatting, and query profiling."""

import logging
import asyncio
import json
import time
import uuid
from typing import List, Dict, Any, Optional, Set, Protocol, Callable
import pandas as pd
from dataclasses import dataclass, field
import httpx
from config import get_settings
from agent.logger import trace_id_var


logger = logging.getLogger(__name__)

_NUMERIC_TYPES = {"int", "tinyint", "smallint", "mediumint", "bigint", "decimal", "float", "double", "numeric", "real"}
_STRING_TYPES = {"varchar", "char", "text", "mediumtext", "longtext", "tinytext", "enum", "set"}
_DATE_TYPES = {"date", "datetime", "timestamp", "year", "time"}


class BudgetExhausted(Exception):
    def __init__(self, message: str, detail: str = ""):
        super().__init__(message)
        self.detail = detail


class Budget:
    def __init__(self, query_limit: int = 20, row_limit: int = 10_000_000, time_limit_seconds: float = 60.0):
        self.query_limit = query_limit
        self.row_limit = row_limit
        self.time_limit = time_limit_seconds
        self.queries_used = 0
        self.rows_fetched = 0
        self.time_used = 0.0
        self._exhausted = False
        self._lock = asyncio.Lock()

    async def check(self) -> None:
        async with self._lock:
            if self._exhausted:
                raise BudgetExhausted("Session DB budget already exhausted", self.summary)
            if self.queries_used >= self.query_limit:
                self._exhausted = True
                raise BudgetExhausted(f"[BUDGET EXHAUSTED] Session query budget ({self.queries_used}/{self.query_limit})", self.summary)
            if self.rows_fetched >= self.row_limit:
                self._exhausted = True
                raise BudgetExhausted(f"Session row budget exhausted ({self.rows_fetched}/{self.row_limit})", self.summary)
            if self.time_used >= self.time_limit:
                self._exhausted = True
                raise BudgetExhausted(f"Session DB time budget exhausted ({self.time_used:.1f}s/{self.time_limit}s)", self.summary)

    async def deduct(self, rows: int, elapsed_ms: float) -> None:
        async with self._lock:
            self.queries_used += 1
            self.rows_fetched += rows
            self.time_used += elapsed_ms / 1000.0

    @property
    def summary(self) -> str:
        return f"q={self.queries_used}/{self.query_limit} rows={self.rows_fetched}/{self.row_limit} time={self.time_used:.1f}s/{self.time_limit}s"


def _types_compatible(t1: str, t2: str) -> bool:
    t1, t2 = t1.lower(), t2.lower()
    if t1 == t2:
        return True
    if t1 in _NUMERIC_TYPES and t2 in _NUMERIC_TYPES:
        return True
    if t1 in _STRING_TYPES and t2 in _STRING_TYPES:
        return True
    if t1 in _DATE_TYPES and t2 in _DATE_TYPES:
        return True
    return False


def _validate_virtual_fk(source_table: str, column: str, ref_table: str, ref_col: str, source_schema: dict, all_schemas: dict, sensitive_tables: Set[str], sensitive_columns: Dict[str, List[str]]) -> None:
    violations = []
    ref_schema = all_schemas.get(ref_table.lower())
    if ref_schema is None:
        violations.append(f"ref_table '{ref_table}' not found in live schema")
    else:
        source_cols = {c["name"] for c in source_schema["columns"]}
        if column not in source_cols:
            violations.append(f"column '{column}' not found in {source_table}")
        ref_cols = {c["name"] for c in ref_schema["columns"]}
        if ref_col not in ref_cols:
            violations.append(f"ref_col '{ref_col}' not found in {ref_table}")
        if not violations:
            src_type = next(c["type"] for c in source_schema["columns"] if c["name"] == column)
            dst_type = next(c["type"] for c in ref_schema["columns"] if c["name"] == ref_col)
            if not _types_compatible(src_type, dst_type):
                violations.append(f"type mismatch: {column}({src_type}) -> {ref_table}.{ref_col}({dst_type})")
        if ref_table.lower() in sensitive_tables:
            violations.append(f"SECURITY: ref_table '{ref_table}' is a sensitive table")
        wildcard = set(sensitive_columns.get("*", []))
        table_blocked = set(sensitive_columns.get(ref_table.lower(), []))
        if ref_col.lower() in {c.lower() for c in wildcard} or ref_col.lower() in {c.lower() for c in table_blocked}:
            violations.append(f"SECURITY: ref_col '{ref_table}.{ref_col}' is a sensitive column")
    if violations:
        logger.warning("Virtual FK validation | %s.%s -> %s.%s | %s", source_table, column, ref_table, ref_col, "; ".join(violations))


# ── DDL formatting (module-level, no DB dependency) ────────────────────────

def _format_ddl_column(c: dict) -> str:
    col_str = f"{c['name']}:{c['type']}"
    if c.get("key") == "PRI":
        col_str += " [PK]"
    if c.get("is_sensitive"):
        col_str += " [SENSITIVE]"
    if c.get("enum"):
        col_str += f" [enum:{c['enum']}]"
    if c.get("values"):
        vals = c["values"]
        if isinstance(vals, dict):
            joined = ", ".join(f"{k}={v}" for k, v in vals.items())
        else:
            joined = str(vals)
        col_str += f" [values: {joined}]"
    if c.get("description"):
        col_str += f" ({c['description']})"
    return col_str


def _format_ddl_foreign_keys(fks: list) -> str:
    fk_parts = []
    for fk in fks:
        label = f"{fk['column']}→{fk['ref_table']}.{fk['ref_col']} (1→many)"
        if fk.get("_virtual"):
            label += " [virtual: relationship hint, not a queryable column]"
        fk_parts.append(label)
    return ", ".join(fk_parts)


def _format_ddl_table(table: str, info: dict, business_context: Optional[List[Dict]] = None) -> str:
    label = "[SENSITIVE]" if info.get("is_sensitive") else ""
    lines = [f"TABLE: {table}{' ' + label if label else ''}"]
    if info.get("description"):
        lines[0] += f" — {info['description']}"
    col_parts = [_format_ddl_column(c) for c in info["columns"]]
    lines.append(f"  C: {', '.join(col_parts)}")
    if info.get("foreign_keys"):
        lines.append(f"  FK: {_format_ddl_foreign_keys(info['foreign_keys'])}")
    if business_context:
        lines.append("  BIZ:")
        for e in business_context:
            title = (e.get("title") or "").strip()
            content = (e.get("content") or "").strip()
            if not title or not content:
                continue
            for i, part in enumerate(content.splitlines()):
                if i == 0:
                    lines.append(f"    - {title}: {part}")
                else:
                    lines.append(f"      {part}")
    return "\n".join(lines)


def compile_ddl(schema: Dict[str, Any], business_context_map: Optional[Dict[str, List[Dict]]] = None) -> str:
    return "\n\n".join(
        _format_ddl_table(t, i, business_context=(business_context_map or {}).get(t.lower()))
        for t, i in schema.items()
    )


# ── QueryResult and QueryExecutor protocol ────────────────────────────────


@dataclass(frozen=True)
class ColumnMeta:
    name: str
    db_type: str
    nullable: bool = True


@dataclass(frozen=True)
class QueryResult:
    query_id: str
    columns: List[ColumnMeta]
    rows: List[List[Any]]
    row_count: int
    truncated: bool = False
    warnings: List[str] = field(default_factory=list)


class QueryExecutor(Protocol):
    async def execute(
        self,
        sql: str,
        *,
        datasource_id: str,
        max_rows: int = 10000,
        timeout_ms: int = 15000,
        query_id: Optional[str] = None,
        user_ref: Optional[int] = None,
        referenced_tables: Optional[List[str]] = None,
    ) -> QueryResult:
        ...


_WRAP_STRINGY_TYPES = {"decimal", "numeric", "bigint", "date", "datetime", "timestamp", "time", "year", "json"}


async def profile_query(executor: QueryExecutor, sql: str, df: pd.DataFrame, *,
                        datasource_id: str, session_id: str, cardinality_threshold: int = 10,
                        user_ref: Optional[int] = None,
                        referenced_tables: Optional[List[str]] = None) -> Optional[Dict[str, Any]]:
    """Accurate metadata for the FULL result of `sql`, computed via SQL
    aggregates so it is unaffected by any row cap applied when materializing
    `df` for the sandbox.

    Returns a dict with the true row count and per-column stats (nulls,
    distinct, min/max/mean, and a top-5 distribution for low-cardinality
    columns), or None if the aggregate queries fail (caller should fall back
    to pandas stats on the materialized df).
    """
    if df is None or df.shape[1] == 0:
        return None
    inner = sql.rstrip().rstrip(";").strip()
    if not inner:
        return None
    query_id = uuid.uuid4().hex[:12]
    try:
        count_result = await executor.execute(
            f"SELECT COUNT(*) AS _n FROM ({inner}) AS _sub",
            datasource_id=datasource_id, session_id=session_id, max_rows=1, timeout_ms=15000,
            query_id=f"prof_cnt_{query_id}",
            user_ref=user_ref, referenced_tables=referenced_tables,
        )
        row_count = int(count_result.rows[0][0]) if count_result.rows else 0
        columns = list(df.columns)
        dtypes = {c: str(df[c].dtype) for c in columns}
        agg = []
        kind: Dict[str, str] = {}
        for col in columns:
            d = dtypes[col]
            qc = "`" + col.replace("`", "``") + "`"
            agg.append(f"SUM({qc} IS NULL) AS `{col}__nulls`")
            agg.append(f"COUNT(DISTINCT {qc}) AS `{col}__distinct`")
            if d.startswith(("int", "float", "uint")):
                kind[col] = "numeric"
                agg.append(f"MIN({qc}) AS `{col}__min`")
                agg.append(f"MAX({qc}) AS `{col}__max`")
                agg.append(f"AVG({qc}) AS `{col}__avg`")
            elif "datetime" in d or "timedelta" in d:
                kind[col] = "temporal"
                agg.append(f"MIN({qc}) AS `{col}__min`")
                agg.append(f"MAX({qc}) AS `{col}__max`")
            else:
                kind[col] = "categorical"
        if not agg:
            return {"row_count": row_count, "columns": columns, "dtypes": dtypes, "stats": {}}
        agg_result = await executor.execute(
            f"SELECT {', '.join(agg)} FROM ({inner}) AS _sub",
            datasource_id=datasource_id, session_id=session_id, max_rows=1, timeout_ms=30000,
            query_id=f"prof_agg_{query_id}",
            user_ref=user_ref, referenced_tables=referenced_tables,
        )
        if not agg_result.rows:
            return {"row_count": row_count, "columns": columns, "dtypes": dtypes, "stats": {}}
        row = agg_result.rows[0]
        col_names = [c.name for c in agg_result.columns]
        row_dict = dict(zip(col_names, row))
        stats: Dict[str, Any] = {}
        for col in columns:
            s: Dict[str, Any] = {
                "kind": kind[col],
                "nulls": int(row_dict.get(f"{col}__nulls", 0) or 0),
                "distinct": int(row_dict.get(f"{col}__distinct", 0) or 0),
            }
            if kind[col] in ("numeric", "temporal"):
                s["min"] = row_dict.get(f"{col}__min")
                s["max"] = row_dict.get(f"{col}__max")
                if kind[col] == "numeric":
                    s["mean"] = row_dict.get(f"{col}__avg")
            stats[col] = s
        for col in columns:
            if kind[col] != "categorical":
                continue
            if stats[col]["distinct"] > cardinality_threshold:
                continue
            qc = "`" + col.replace("`", "``") + "`"
            dist_sql = (
                f"SELECT {qc} AS _v, COUNT(*) AS _c FROM ({inner}) AS _sub "
                f"WHERE {qc} IS NOT NULL GROUP BY {qc} ORDER BY _c DESC LIMIT 5"
            )
            try:
                ddf_result = await executor.execute(
                    dist_sql, datasource_id=datasource_id, session_id=session_id, max_rows=5, timeout_ms=15000,
                    query_id=f"prof_dist_{query_id}",
                    user_ref=user_ref, referenced_tables=referenced_tables,
                )
                total = row_count - stats[col]["nulls"]
                dist = []
                for r in ddf_result.rows:
                    v = r[0]
                    c = int(r[1])
                    pct = (c / total * 100) if total else 0.0
                    dist.append((v, c, pct))
                if dist:
                    stats[col]["distribution"] = dist
            except Exception:
                pass
        return {"row_count": row_count, "columns": columns, "dtypes": dtypes, "stats": stats}
    except Exception as e:
        logger.warning("profile_query failed | sql=%.100s | error=%s", sql, str(e)[:200])
        return None


class RemoteQueryExecutor:
    """Executes SQL queries via a remote HTTP data plane (Laravel).

    Each executor targets one instance's data-plane URL. Instead of signing
    requests, it forwards a delegation token (JWT-like, signed by the client
    instance) that Laravel verifies against the client's registered public key.
    """

    class DelegationStore(Protocol):
        """Lookup interface for per-session delegation tokens."""
        def get(self, client_id: str, session_id: str) -> str | None: ...

    def __init__(self, base_url: str, delegation_store: "RemoteQueryExecutor.DelegationStore"):
        self._base_url = base_url.rstrip("/") + "/"
        self._delegation_store = delegation_store
        self._client = httpx.AsyncClient(
            base_url=self._base_url,
            timeout=httpx.Timeout(connect=2.0, read=35.0, write=5.0, pool=5.0),
            limits=httpx.Limits(max_keepalive_connections=20, max_connections=100),
        )
        logger.info("RemoteQueryExecutor initialized | base_url=%s", self._base_url)

    async def execute(
        self,
        sql: str,
        *,
        datasource_id: str,
        session_id: str,
        max_rows: int = 10000,
        timeout_ms: int = 15000,
        query_id: Optional[str] = None,
        user_ref: Optional[int] = None,
        referenced_tables: Optional[List[str]] = None,
    ) -> QueryResult:
        qid = query_id or uuid.uuid4().hex[:12]
        body = {
            "datasource_id": datasource_id,
            "query_id": qid,
            "sql": sql,
            "max_rows": max_rows,
            "timeout_ms": timeout_ms,
            "user_ref": user_ref,
            "referenced_tables": list(referenced_tables or []),
        }
        logger.debug("RemoteQueryExecutor executing | qid=%s | sql=%.200s", qid, sql)
        body_bytes = json.dumps(body).encode()
        delegation_token = self._delegation_store.get(datasource_id.split(".")[0], session_id)
        if not delegation_token:
            raise RuntimeError("No delegation token for this session — cannot call data plane")
        headers = {"X-Request-ID": qid, "X-Delegation-Token": delegation_token, "Accept": "application/json", "Content-Type": "application/json"}
        tid = trace_id_var.get()
        if tid and tid != "-":
            headers["X-Trace-Id"] = tid
        try:
            t0 = time.monotonic()
            response = await self._client.post("query", content=body_bytes, headers=headers)
        except httpx.ConnectError as e:
            raise RuntimeError(f"Data plane unreachable: {e}")
        except httpx.ReadTimeout as e:
            elapsed_ms = int((time.monotonic() - t0) * 1000)
            raise RuntimeError(f"Data plane did not respond within {elapsed_ms}ms (requested budget {timeout_ms}ms): {e}")
        except httpx.TimeoutException as e:
            raise RuntimeError(f"Data plane request timed out: {e}")
        logger.debug("RemoteQueryExecutor response | status=%s", response.status_code)
        try:
            data = response.json()
        except json.JSONDecodeError as e:
            raise RuntimeError(f"Data plane returned non-JSON response (HTTP {response.status_code}): {e}")
        if response.status_code == 422:
            raise RuntimeError(f"Data plane validation error (HTTP 422): {json.dumps(data)}")
        if "error" in data:
            err = data["error"]
            raise RuntimeError(f"Data plane error [{err.get('code', 'UNKNOWN')}]: {err.get('message', str(data))}")
        response.raise_for_status()
        columns = [ColumnMeta(**c) for c in data.get("columns", [])]
        return QueryResult(
            query_id=data.get("query_id", qid),
            columns=columns,
            rows=data.get("rows", []),
            row_count=data.get("row_count", 0),
            truncated=data.get("truncated", False),
            warnings=data.get("warnings", []),
        )

    async def close(self):
        await self._client.aclose()
        logger.info("RemoteQueryExecutor closed")
