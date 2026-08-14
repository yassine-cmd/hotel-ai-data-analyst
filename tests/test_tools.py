"""Tests for the agent.tools pure functions and input parsers — chart
validation/coercion, SQL query parsing, table/question parsing, and the
envelope builder.
"""
import pandas as pd

from agent.tools.base import build_envelope, build_tool_schemas, BaseTool
from agent.tools.chart import (
    ChartTool,
    _chart_validate_shape,
    _chart_coerce_numeric,
    _chart_cap_categories,
    _chart_sort_and_limit,
    _chart_detect_axis_type,
)
from agent.tools.query import SQLTool
from agent.tools.describe import DescribeTool
from agent.tools.ask import QuestionTool


# --- base.py ----------------------------------------------------------------


def test_build_envelope_fields():
    env = build_envelope("success", error=None, error_kind=None, foo="bar")
    assert env["status"] == "success"
    assert env["error"] is None
    assert env["foo"] == "bar"
    assert "partial_reason" not in env


def test_build_envelope_partial_reason():
    env = build_envelope("partial", partial_reason="some_queries_failed", message="m")
    assert env["partial_reason"] == "some_queries_failed"
    assert env["message"] == "m"


def test_build_tool_schemas():
    schemas = build_tool_schemas([SQLTool(), ChartTool()])
    names = {s["function"]["name"] for s in schemas}
    assert "execute_sql" in names
    assert "create_chart_spec" in names


# --- chart.py validation ----------------------------------------------------


def test_chart_validate_shape_bar_ok():
    ok, errs = _chart_validate_shape("bar", "x", ["y"], None, False)
    assert ok
    assert errs == []


def test_chart_validate_shape_requires_x():
    ok, errs = _chart_validate_shape("bar", "", ["y"], None, False)
    assert not ok
    assert any("x" in e.lower() for e in errs)


def test_chart_validate_shape_pie_single_y():
    ok, errs = _chart_validate_shape("pie", "cat", ["v1", "v2"], None, False)
    assert not ok
    assert any("exactly one" in e for e in errs)


def test_chart_validate_shape_pie_no_group_by():
    ok, errs = _chart_validate_shape("pie", "cat", ["v"], "g", False)
    assert not ok
    assert any("group_by" in e for e in errs)


def test_chart_validate_shape_stacked_requires_group_or_multi_y():
    ok, errs = _chart_validate_shape("bar", "x", ["y"], None, True)
    assert not ok
    assert any("stacked" in e.lower() for e in errs)


def test_chart_validate_shape_stacked_multi_y_without_group_ok():
    ok, errs = _chart_validate_shape("bar", "x", ["a", "b"], None, True)
    assert ok
    assert errs == []


def test_chart_validate_shape_stacked_not_supported():
    ok, errs = _chart_validate_shape("line", "x", ["y"], "g", True)
    assert not ok
    assert any("not supported" in e for e in errs)


def test_chart_validate_shape_unknown_type():
    ok, errs = _chart_validate_shape("bogus", "x", ["y"], None, False)
    assert not ok
    assert any("Unknown" in e for e in errs)


def test_chart_validate_shape_histogram_no_y():
    ok, errs = _chart_validate_shape("histogram", "x", ["y"], None, False)
    assert not ok
    assert any("does not use" in e for e in errs)


def test_chart_coerce_numeric_warns_on_loss():
    # 3 of 4 values non-numeric -> >0.5 loss ratio -> warning
    df = pd.DataFrame({"v": ["1", "x", "y", "z"]})
    out, warnings = _chart_coerce_numeric(df, ["v"])
    assert any("not numeric" in w for w in warnings)
    assert out["v"].isna().sum() == 3


def test_chart_coerce_numeric_clean():
    df = pd.DataFrame({"v": ["1", "2", "3", "4"]})
    out, warnings = _chart_coerce_numeric(df, ["v"])
    assert warnings == []
    assert pd.api.types.is_numeric_dtype(out["v"])


def test_chart_cap_categories_creates_other():
    df = pd.DataFrame({
        "cat": [f"c{i}" for i in range(25)],
        "val": list(range(25)),
    })
    out, warnings, rollup = _chart_cap_categories(df, "cat", ["val"], max_categories=20)
    assert "Other" in out["cat"].values
    # 19 kept + 1 "Other"
    assert len(out) == 20
    assert any("Other" in w for w in warnings)
    assert rollup > 0


def test_chart_cap_categories_noop_when_small():
    df = pd.DataFrame({"cat": ["a", "b"], "val": [1, 2]})
    out, warnings, rollup = _chart_cap_categories(df, "cat", ["val"], max_categories=20)
    assert len(out) == 2
    assert warnings == []
    assert rollup == 0


def test_chart_sort_and_limit():
    df = pd.DataFrame({"x": [3, 1, 2], "y": [30, 10, 20]})
    out, warnings, limited = _chart_sort_and_limit(df, "x", ["y"], sort_by="x", sort_order="asc", limit=2)
    assert list(out["x"]) == [1, 2]
    assert any("top 2" in w for w in warnings)
    assert limited is True


def test_chart_detect_axis_type():
    assert _chart_detect_axis_type(pd.Series([1, 2, 3])) == "numeric"
    assert _chart_detect_axis_type(pd.Series(pd.to_datetime(["2025-01-01", "2025-02-01"]))) == "temporal"
    assert _chart_detect_axis_type(pd.Series(["a", "b", "c"])) == "category"


# --- query.py input parsing -------------------------------------------------


def test_sql_build_queries_from_list():
    q = SQLTool._build_queries({"queries": [{"sql": "SELECT 1", "df_name": "d1"}]})
    assert q == [{"sql": "SELECT 1", "df_name": "d1"}]


def test_sql_build_queries_from_json_string():
    q = SQLTool._build_queries({"queries": '[{"sql": "SELECT 1", "df_name": "d1"}]'})
    assert q == [{"sql": "SELECT 1", "df_name": "d1"}]


def test_sql_build_queries_invalid_df_name():
    q = SQLTool._build_queries({"queries": [{"sql": "SELECT 1", "df_name": "1bad"}]})
    assert q == []


def test_sql_build_queries_empty():
    assert SQLTool._build_queries({"queries": []}) == []
    assert SQLTool._build_queries({}) == []


def test_sql_validate_result_null_join_key():
    df = pd.DataFrame({"id_client": [None, None], "name": ["a", "b"]})
    tree = _parse_tree("SELECT c.id_client FROM client c")
    warns = SQLTool._validate_result("SELECT c.id_client FROM client c", df, {}, tree=tree)
    assert any("entirely NULL" in w for w in warns)


def test_sql_validate_result_zero_row_join():
    df = pd.DataFrame({"id": [], "amount": []})
    tree = _parse_tree("SELECT r.id, r.amount FROM reservation r JOIN client c ON r.client_id = c.id")
    warns = SQLTool._validate_result("...", df, {"reservation": {}, "client": {}}, tree=tree)
    assert any("0 rows" in w for w in warns)


def _parse_tree(sql):
    from agent.tools._helpers import _parse_sql_safely
    return _parse_sql_safely(sql)


def test_sql_enrich_unknown_column_error():
    schema = {"client": {"columns": [{"name": "id"}, {"name": "name"}]}}
    msg = SQLTool._enrich_unknown_column_error(
        "SELECT nonexistent FROM client", "Unknown column 'nonexistent'", schema
    )
    assert "Available columns" in msg
    assert "id" in msg


# --- describe.py ------------------------------------------------------------


def test_describe_parse_tables_list():
    assert DescribeTool._parse_tables({"tables": ["client", "reservation"]}) == ["client", "reservation"]


def test_describe_parse_tables_comma_string():
    assert DescribeTool._parse_tables({"tables": "client, reservation"}) == ["client", "reservation"]


def test_describe_parse_tables_json_string():
    assert DescribeTool._parse_tables({"tables": '["client"]'}) == ["client"]


def test_describe_parse_tables_empty():
    assert DescribeTool._parse_tables({"tables": []}) == []


# --- ask.py -----------------------------------------------------------------


def test_question_parse_list():
    qs = QuestionTool._parse_questions({
        "questions": [{"question": "When?", "options": ["Q1", "Q2"], "multi": True}]
    })
    assert len(qs) == 1
    assert qs[0]["question"] == "When?"
    assert qs[0]["options"] == ["Q1", "Q2"]
    assert qs[0]["multi"] is True


def test_question_parse_single_dict():
    qs = QuestionTool._parse_questions({"questions": {"question": "Scope?"}})
    assert len(qs) == 1
    assert qs[0]["question"] == "Scope?"


def test_question_parse_skips_blank():
    qs = QuestionTool._parse_questions({"questions": [{"question": "  "}, {"question": "ok"}]})
    assert len(qs) == 1


def test_question_parse_empty():
    assert QuestionTool._parse_questions({"questions": []}) == []


# ── SQLTool.run integration with fakes ─────────────────────────────────────


async def test_sql_tool_run_success():
    from agent.tools.query import SQLTool
    from agent.db import Budget, QueryResult, ColumnMeta

    class _FakeExecutor:
        async def execute(self, sql, *, datasource_id="test", session_id="", max_rows=10000, timeout_ms=15000, query_id=None, user_ref=None, referenced_tables=None):
            import pandas as pd
            return QueryResult(
                query_id="q1",
                columns=[ColumnMeta(name="id", db_type="INT"), ColumnMeta(name="name", db_type="VARCHAR")],
                rows=[["1", "a"], ["2", "b"]],
                row_count=2,
            )

    class _FakeGuard:
        def check_access(self, sql, **kw):
            return True, []
        def rewrite_sql(self, sql, **kw):
            return sql, []
        def strip_columns(self, df, sql, **kw):
            return df
        def _build_alias_map(self, tree):
            return {}

    tool = SQLTool()
    budget = Budget(query_limit=10, row_limit=10000, time_limit_seconds=60)
    context = {
        "executor": _FakeExecutor(),
        "schema_dict": {"t": {"columns": [{"name": "id", "type": "int"}]}},
        "sensitive_tables": set(),
        "sensitive_columns": {},
        "guard": _FakeGuard(),
        "session_budget": budget,
        "session_dataframes": {},
        "session_id": "s1",
        "client_id": "c1",
        "datasource_id": "test",
    }
    result = await tool.run(
        {"queries": [{"sql": "SELECT * FROM t", "df_name": "df1"}]},
        context,
    )
    assert result.data["status"] == "success"
    results = result.data.get("results", [])
    assert len(results) == 1
    assert results[0].get("df_name") == "df1"
    # Ensure result contains the data we expect from the executor
    # profile_query re-executes the SQL via the fake executor and reads the
    # first cell of the first row as the true row count — here it's "1"
    assert results[0].get("true_row_count") == 1
    # Shape is [true_row_count, column_count]
    assert results[0].get("shape") == [1, 2]
    assert results[0].get("columns") == ["id", "name"]


async def test_sql_tool_run_budget_exhausted():
    from agent.tools.query import SQLTool
    from agent.db import Budget, QueryResult, ColumnMeta

    class _FakeExecutor:
        async def execute(self, sql, *, datasource_id="test", max_rows=10000, timeout_ms=15000, query_id=None):
            return QueryResult(query_id="q1", columns=[ColumnMeta(name="id", db_type="INT")], rows=[["1"]], row_count=1)

    tool = SQLTool()
    budget = Budget(query_limit=0, row_limit=0, time_limit_seconds=0)
    context = {
        "executor": _FakeExecutor(),
        "schema_dict": {"t": {"columns": [{"name": "id", "type": "int"}]}},
        "sensitive_tables": set(),
        "sensitive_columns": {},
        "guard": None,
        "session_budget": budget,
        "session_dataframes": {},
        "session_id": "s1",
        "client_id": "c1",
        "datasource_id": "test",
    }
    result = await tool.run({"queries": [{"sql": "SELECT 1", "df_name": "d1"}]}, context)
    assert "error" in result.data
    assert "budget" in result.data["error"].lower()


async def test_sql_tool_run_permission_block_stops_before_executor():
    """A non-sensitive table outside the user's permission grants must be
    rejected with its own error kind BEFORE any SQL reaches the executor."""
    from agent.tools.query import SQLTool
    from agent.db import Budget

    class _FakeExecutor:
        def __init__(self):
            self.calls = 0

        async def execute(self, *args, **kwargs):
            self.calls += 1
            raise AssertionError("executor must not be reached for a permission-blocked table")

    class _FakeGuard:
        def check_access(self, sql, **kw):
            return True, []
        def rewrite_sql(self, sql, **kw):
            return sql, []
        def strip_columns(self, df, sql, **kw):
            return df
        def _build_alias_map(self, tree):
            return {}

    tool = SQLTool()
    executor = _FakeExecutor()
    budget = Budget(query_limit=10, row_limit=10000, time_limit_seconds=60)
    context = {
        "executor": executor,
        "schema_dict": {"t": {"columns": [{"name": "id", "type": "int"}]}},
        "sensitive_tables": set(),
        "unauthorized_tables": {"t"},
        "guard": _FakeGuard(),
        "session_budget": budget,
        "session_dataframes": {},
        "session_id": "s1",
        "client_id": "c1",
        "datasource_id": "test",
    }
    result = await tool.run(
        {"queries": [{"sql": "SELECT * FROM t", "df_name": "df1"}]},
        context,
    )
    assert executor.calls == 0
    assert result.data["status"] == "error"
    results = result.data.get("results", [])
    assert len(results) == 1
    assert results[0]["error_kind"] == "permission"
    assert "not in this user's permission grants" in results[0]["error"]


async def test_sql_tool_run_sensitive_wins_over_permission():
    """A table that is BOTH globally sensitive AND outside the user's grants is
    blocked as sensitive (the guard runs first), never as permission."""
    from agent.tools.query import SQLTool
    from agent.tools.guard import SensitiveDataGuard
    from agent.db import Budget

    class _FakeExecutor:
        def __init__(self):
            self.calls = 0

        async def execute(self, *args, **kwargs):
            self.calls += 1
            raise AssertionError("executor must not be reached")

    schema = {
        "t": {"is_sensitive": True, "columns": [{"name": "id", "type": "int"}]},
    }
    guard = SensitiveDataGuard(
        lambda: schema,
        sensitive_tables=lambda: {"t"},
        sensitive_columns=lambda: {"*": []},
    )
    tool = SQLTool()
    budget = Budget(query_limit=10, row_limit=10000, time_limit_seconds=60)
    context = {
        "executor": _FakeExecutor(),
        "schema_dict": schema,
        "sensitive_tables": {"t"},
        "unauthorized_tables": {"t"},
        "guard": guard,
        "session_budget": budget,
        "session_dataframes": {},
        "session_id": "s1",
        "client_id": "c1",
        "datasource_id": "test",
    }
    result = await tool.run(
        {"queries": [{"sql": "SELECT id FROM t", "df_name": "df1"}]},
        context,
    )
    assert result.data["status"] == "error"
    results = result.data.get("results", [])
    assert len(results) == 1
    assert results[0]["error_kind"] == "sensitive_table"


async def test_sql_tool_run_allows_granted_non_sensitive():
    """A table that IS granted and is NOT sensitive executes normally."""
    from agent.tools.query import SQLTool
    from agent.db import Budget, QueryResult, ColumnMeta

    class _FakeExecutor:
        async def execute(self, sql, *, datasource_id="test", session_id="", max_rows=10000, timeout_ms=15000, query_id=None, user_ref=None, referenced_tables=None):
            return QueryResult(query_id="q1", columns=[ColumnMeta(name="id", db_type="INT")], rows=[["1"]], row_count=1)

    class _FakeGuard:
        def check_access(self, sql, **kw):
            return True, []
        def rewrite_sql(self, sql, **kw):
            return sql, []
        def strip_columns(self, df, sql, **kw):
            return df
        def _build_alias_map(self, tree):
            return {}

    tool = SQLTool()
    budget = Budget(query_limit=10, row_limit=10000, time_limit_seconds=60)
    context = {
        "executor": _FakeExecutor(),
        "schema_dict": {"t": {"columns": [{"name": "id", "type": "int"}]}},
        "sensitive_tables": set(),
        "unauthorized_tables": set(),
        "guard": _FakeGuard(),
        "session_budget": budget,
        "session_dataframes": {},
        "session_id": "s1",
        "client_id": "c1",
        "datasource_id": "test",
    }
    result = await tool.run(
        {"queries": [{"sql": "SELECT id FROM t", "df_name": "df1"}]},
        context,
    )
    assert result.data["status"] == "success"


# ── ChartTool.run with fakes ────────────────────────────────────────────────


async def test_chart_tool_run_missing_dataframe():
    from agent.tools.chart import ChartTool

    class _FakeSM:
        @property
        def store(self):
            return self

        async def get(self, c, s, name):
            return None

        async def list(self, c, s):
            return []

    tool = ChartTool()
    context = {
        "session_id": "s1",
        "client_id": "c1",
        "session_manager": _FakeSM(),
        "session_dataframes": {},
    }
    result = await tool.run({
        "df_name": "nonexistent",
        "chart_type": "bar",
        "x": "a",
        "y": ["b"],
    }, context)
    assert "error" in result.data
    assert "nonexistent" in result.data["error"].lower()


async def test_chart_tool_y_labels_passthrough():
    from agent.tools.chart import ChartTool
    import pandas as pd

    class _FakeSM:
        @property
        def store(self):
            return self

        async def get(self, c, s, name):
            return pd.DataFrame({
                "mois": ["janvier", "février", "mars"],
                "nb_arrivees": [100, 120, 140],
                "nb_reservations": [102, 121, 141],
            })

        async def list(self, c, s):
            return []

    tool = ChartTool()
    context = {
        "session_id": "s1",
        "client_id": "c1",
        "session_manager": _FakeSM(),
        "session_dataframes": {},
    }
    result = await tool.run({
        "df_name": "df",
        "chart_type": "bar",
        "x": "mois",
        "y": ["nb_arrivees", "nb_reservations"],
        "y_labels": {
            "nb_arrivees": "Arrivées",
            "nb_reservations": "Réservations créées",
            "unknown_col": "Ignored",
        },
    }, context)
    assert result.data.get("status") == "success"
    spec = result.data["chart_spec"]
    assert spec["y_labels"] == {
        "nb_arrivees": "Arrivées",
        "nb_reservations": "Réservations créées",
    }
    assert "unknown_col" not in spec["y_labels"]


# ── DescribeTool.run with fakes ─────────────────────────────────────────────


async def test_describe_tool_run():
    from agent.tools.describe import DescribeTool

    tool = DescribeTool()
    context = {
        "schema_dict": {"client": {"columns": [{"name": "id", "type": "int", "key": ""}]}},
        "session_id": "s1",
        "client_id": "c1",
    }
    result = await tool.run({"tables": ["client"]}, context)
    assert result.data.get("error") is None
    assert result.data.get("status") == "success"
    assert "client" in result.data.get("described", [])
    assert "TABLE: client" in result.data.get("ddl", "")
    assert "id" in result.data.get("ddl", "")
