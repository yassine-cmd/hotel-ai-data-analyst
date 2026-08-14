"""Tests for agent.tools._helpers — SQL parsing, table extraction, DataFrame
metadata, and size-budgeted oldest-first eviction.
"""
import pandas as pd

from agent.tools._helpers import (
    _parse_sql_safely,
    _top_level_tables,
    _df_metadata,
    _enforce_df_budget,
)


def test_parse_sql_safely_ok():
    tree = _parse_sql_safely("SELECT id FROM client")
    assert tree is not None
    top = _top_level_tables(tree)
    assert any(t.name.lower() == "client" for t in top)


def test_parse_sql_safely_invalid():
    # malformed SQL should not raise — returns None
    assert _parse_sql_safely("SELECT FROM FROM WHERE (((") is None


def test_top_level_tables_excludes_subqueries():
    tree = _parse_sql_safely(
        "SELECT a.id FROM client a WHERE a.id IN (SELECT client_id FROM reservation)"
    )
    top = _top_level_tables(tree)
    names = {t.name for t in top}
    assert "client" in names
    # the inner table is inside a subquery -> excluded
    assert "reservation" not in names


def test_df_metadata_shape():
    df = pd.DataFrame({"a": [1, 2], "b": ["x", "y"]})
    meta = _df_metadata(df)
    assert meta["shape"] == [2, 2]
    assert meta["columns"] == ["a", "b"]
    assert "a" in meta["dtypes"]
    assert "b" in meta["dtypes"]


class FakeStore:
    def __init__(self, sizes):
        self.sizes = dict(sizes)
        self.evicted = []

    def size_of(self, c, s, name=None):
        if name is None:
            return sum(self.sizes.values())
        return self.sizes.get(name, 0)

    def evict(self, c, s, name):
        self.evicted.append(name)
        self.sizes.pop(name, None)


async def test_enforce_df_budget_within_limit():
    session_dataframes = {"df1": {}, "df2": {}}
    fake_sm = type("SM", (), {"store": FakeStore({"df1": 100, "df2": 100})})()

    await _enforce_df_budget(session_dataframes, fake_sm, "sid", client_id="c1")
    # tiny total (200B) well under default 128MiB budget and count ceiling -> nothing evicted
    assert len(session_dataframes) == 2
    assert fake_sm.store.evicted == []


async def test_enforce_df_budget_evicts_oldest_by_size():
    from unittest.mock import patch

    with patch("config.get_settings") as mock_gs:
        mock_gs.return_value.SESSION_DF_BUDGET_BYTES = 1000
        mock_gs.return_value.SESSION_DATAFRAMES_MAX = 15
        session_dataframes = {"old": {}, "mid": {}, "new": {}}
        fake_sm = type("SM", (), {"store": FakeStore({"old": 500, "mid": 500, "new": 500})})()

        await _enforce_df_budget(session_dataframes, fake_sm, "sid", client_id="c1")
        # total 1500 > 1000 -> evict oldest ("old", 500B); 1000 left fits budget
        assert len(session_dataframes) == 2
        assert "old" not in session_dataframes
        assert fake_sm.store.evicted == ["old"]


async def test_enforce_df_budget_evicts_multiple_until_budget_met():
    from unittest.mock import patch

    with patch("config.get_settings") as mock_gs:
        mock_gs.return_value.SESSION_DF_BUDGET_BYTES = 1000
        mock_gs.return_value.SESSION_DATAFRAMES_MAX = 15
        session_dataframes = {"old": {}, "mid": {}, "new": {}}
        fake_sm = type("SM", (), {"store": FakeStore({"old": 700, "mid": 700, "new": 700})})()

        await _enforce_df_budget(session_dataframes, fake_sm, "sid", client_id="c1")
        # total 2100 > 1000 -> evict "old" (700), total 1400 still > 1000 -> evict "mid" (700), total 700 <= 1000
        assert len(session_dataframes) == 1
        assert "new" in session_dataframes
        assert fake_sm.store.evicted == ["old", "mid"]


async def test_enforce_df_budget_count_ceiling():
    from unittest.mock import patch

    with patch("config.get_settings") as mock_gs:
        mock_gs.return_value.SESSION_DF_BUDGET_BYTES = 134_217_728
        mock_gs.return_value.SESSION_DATAFRAMES_MAX = 2
        session_dataframes = {"old": {}, "mid": {}, "new": {}}
        fake_sm = type("SM", (), {"store": FakeStore({"old": 1, "mid": 1, "new": 1})})()

        await _enforce_df_budget(session_dataframes, fake_sm, "sid", client_id="c1")
        # tiny sizes fit the budget, but the count ceiling (2) still trims oldest
        assert len(session_dataframes) == 2
        assert "old" not in session_dataframes
        assert fake_sm.store.evicted == ["old"]
