"""Internal helpers: SQL parsing (sqlglot), top-level table extraction,
DataFrame metadata, and size-budgeted oldest-first DataFrame eviction."""

import logging
from typing import List

import sqlglot
from sqlglot import exp

logger = logging.getLogger(__name__)
_audit_logger = logging.getLogger("audit")


def _parse_sql_safely(sql: str):
    try:
        return sqlglot.parse_one(sql, dialect="mysql")
    except Exception:
        logger.warning("sqlglot parse failed | sql=%.200s", sql)
        return None


def _top_level_tables(tree: exp.Expression) -> List[exp.Table]:
    found: List[exp.Table] = []
    for t in tree.find_all(exp.Table):
        parent = t.parent
        in_sub = False
        while parent is not None:
            if isinstance(parent, (exp.Subquery, exp.CTE)):
                in_sub = True
                break
            parent = parent.parent
        if not in_sub:
            found.append(t)
    return found


def _df_metadata(df) -> dict:
    return {"shape": list(df.shape), "columns": list(df.columns), "dtypes": {col: str(dtype) for col, dtype in df.dtypes.items()}}


async def _enforce_df_budget(session_dataframes: dict, session_manager, session_id: str, client_id: str) -> None:
    """Evict oldest DataFrames until total on-disk size is within
    SESSION_DF_BUDGET_BYTES, then apply the SESSION_DATAFRAMES_MAX count
    ceiling as a prompt-hygiene floor.

    Eviction is a HARD delete (file + cache + metadata together), so the agent
    listing, the disk, and memory stay the same set.
    """
    from config import get_settings
    settings = get_settings()
    budget = settings.SESSION_DF_BUDGET_BYTES
    max_dfs = settings.SESSION_DATAFRAMES_MAX
    store = session_manager.store if session_manager else None

    total = store.size_of(client_id, session_id) if store else 0
    evicted = []
    # Oldest-first: session_dataframes is insertion-ordered, so iteration order
    # is creation order and next(iter(...)) is the oldest.
    if store and total > budget:
        for name in list(session_dataframes):
            if total <= budget:
                break
            size = store.size_of(client_id, session_id, name)
            store.evict(client_id, session_id, name)
            session_dataframes.pop(name, None)
            total -= size
            evicted.append(name)
            logger.info("Budget-evicted dataframe | name=%s | freed=%d | remaining_total=%d", name, size, total)

    # Count ceiling (prompt-hygiene, not a storage policy): cap the number of
    # names the model sees even when individual dfs are tiny.
    while len(session_dataframes) > max_dfs:
        oldest = next(iter(session_dataframes))
        session_dataframes.pop(oldest, None)
        if store:
            store.evict(client_id, session_id, oldest)
        evicted.append(oldest)
        logger.info("Count-capped dataframe | name=%s", oldest)

    if evicted:
        logger.info("Post-turn df eviction | evicted=%s", evicted)
