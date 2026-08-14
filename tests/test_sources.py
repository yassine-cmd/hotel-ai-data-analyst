import pytest

from agent.step_loop import _build_sources


def test_execute_sql_produces_sql_source():
    calls = [{
        "tool": "execute_sql",
        "status": "success",
        "args": {"queries": [{"sql": "SELECT COUNT(*) FROM bookings", "df_name": "df1"}]},
        "result": {
            "results": [{
                "status": "success",
                "true_row_count": 42,
                "tables": ["bookings"],
                "df_name": "df1",
            }],
        },
    }]
    sources = _build_sources(calls)
    assert len(sources) == 1
    s = sources[0]
    assert s["type"] == "sql"
    assert "SELECT COUNT(*)" in s["sql"]
    assert s["row_count"] == 42
    assert s["tables"] == ["bookings"]
    assert s["description"] == "Queried bookings (42 rows)"


def test_execute_sql_partial_includes_successful_results():
    calls = [{
        "tool": "execute_sql",
        "status": "partial",
        "args": {"queries": [
            {"sql": "SELECT * FROM ok", "df_name": "ok"},
            {"sql": "SELECT * FROM bad", "df_name": "bad"},
        ]},
        "result": {
            "results": [
                {"status": "success", "true_row_count": 10, "tables": ["ok"], "df_name": "ok"},
                {"status": "error", "error": "Syntax error", "df_name": "bad"},
            ],
        },
    }]
    sources = _build_sources(calls)
    assert len(sources) == 1
    assert sources[0]["type"] == "sql"
    assert "ok" in sources[0]["sql"]


def test_describe_table_produces_table_sources():
    calls = [{
        "tool": "describe_table",
        "status": "success",
        "args": {"tables": ["bookings", "rooms"]},
        "result": {"described": ["bookings", "rooms"], "output": "..."},
    }]
    sources = _build_sources(calls)
    assert len(sources) == 2
    assert sources[0]["type"] == "table"
    assert sources[0]["table_name"] == "bookings"
    assert sources[1]["table_name"] == "rooms"
    assert sources[0]["description"] == "Explored bookings structure"


def test_create_chart_spec_produces_chart_source():
    calls = [{
        "tool": "create_chart_spec",
        "status": "success",
        "args": {"df_name": "df1", "chart_type": "bar", "x": "month"},
        "result": {
            "chart_spec": {
                "chart_type": "bar",
                "title": "Revenue by Month",
                "token": "[CHART_0]",
            },
        },
    }]
    sources = _build_sources(calls)
    assert len(sources) == 1
    s = sources[0]
    assert s["type"] == "chart"
    assert s["chart_type"] == "bar"
    assert s["title"] == "Revenue by Month"
    assert "Generated bar chart" in s["description"]


def test_no_tools_returns_empty_sources():
    assert _build_sources([]) == []


def test_only_question_tool_returns_empty():
    calls = [{
        "tool": "question",
        "status": "success",
        "args": {"questions": [{"question": "Which month?"}]},
        "result": {},
    }]
    assert _build_sources(calls) == []


def test_sources_capped_at_20():
    calls = []
    for i in range(25):
        calls.append({
            "tool": "describe_table",
            "status": "success",
            "args": {"tables": [f"table_{i}"]},
            "result": {"described": [f"table_{i}"]},
        })
    sources = _build_sources(calls)
    assert len(sources) == 20


def test_long_sql_truncated():
    sql = "SELECT " + "a, " * 100 + " FROM very_long_query"
    calls = [{
        "tool": "execute_sql",
        "status": "success",
        "args": {"queries": [{"sql": sql, "df_name": "df1"}]},
        "result": {
            "results": [{"status": "success", "true_row_count": 0, "tables": []}],
        },
    }]
    sources = _build_sources(calls)
    assert len(sources[0]["sql"]) == 200
    assert sources[0]["sql"].endswith("…") is False  # just truncated, no ellipsis added


def test_mixed_tools_produces_varied_sources():
    calls = [
        {
            "tool": "execute_sql",
            "status": "success",
            "args": {"queries": [{"sql": "SELECT 1", "df_name": "df1"}]},
            "result": {"results": [{"status": "success", "true_row_count": 1, "tables": ["t1"]}]},
        },
        {
            "tool": "describe_table",
            "status": "success",
            "args": {"tables": ["t2"]},
            "result": {"described": ["t2"]},
        },
        {
            "tool": "create_chart_spec",
            "status": "success",
            "args": {},
            "result": {"chart_spec": {"chart_type": "line", "title": "Trend"}},
        },
    ]
    sources = _build_sources(calls)
    assert len(sources) == 3
    types = [s["type"] for s in sources]
    assert types == ["sql", "table", "chart"]
