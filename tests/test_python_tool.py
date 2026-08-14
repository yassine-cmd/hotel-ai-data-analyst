import pandas as pd
import pytest

from agent.tools.python_tool import PythonTool, _validate_python_code


# ── Code validation ─────────────────────────────────────────────────────────


def test_validate_pass():
    assert _validate_python_code("x = 1\nprint(x)") is None


def test_validate_syntax_error():
    with pytest.raises(ValueError, match="syntax"):
        _validate_python_code("x = {")


def test_validate_blocked_import_os():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("import os")


def test_validate_blocked_import_subprocess():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("import subprocess")


def test_validate_blocked_import_sys():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("import sys")


def test_validate_blocked_call_eval():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("eval('1+1')")


def test_validate_blocked_call_exec():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("exec('x=1')")


def test_validate_blocked_call_compile():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("compile('x=1', '', 'exec')")


def test_validate_blocked_call_import_function():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("__import__('os')")


def test_validate_blocked_importlib_module():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("import importlib")


def test_validate_blocked_import_module_attribute():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("importlib.import_module('os')")


def test_validate_blocked_import_module_from_import():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("from importlib import import_module")


def test_validate_blocked_io_open():
    with pytest.raises(ValueError, match="not allowed"):
        _validate_python_code("import io\nio.open('/tmp/x', 'w')")


def test_validate_innocent_import_allowed():
    assert _validate_python_code("import pandas as pd") is None
    assert _validate_python_code("import numpy as np") is None
    assert _validate_python_code("import math") is None


def test_validate_blocked_keyword_in_bytes():
    with pytest.raises(ValueError, match="blocked pattern"):
        _validate_python_code("x = b'__import__'")


# ── PythonTool.run with fake sandbox ────────────────────────────────────────


class _FakeSandbox:
    def __init__(self, output="", data=None, error=None):
        self.output = output
        self.data = data or {}
        self.error = error

    async def execute(self, code, session_id=None, dataframes=None):
        return {"output": self.output, "data": self.data, "error": self.error}


class _FakeSM:
    def __init__(self):
        self.stored = {}

    @property
    def store(self):
        return self

    async def list(self, c, s):
        return list(self.stored.keys())

    async def get(self, c, s, name):
        return self.stored.get(name)

    async def put(self, c, s, name, df):
        self.stored[name] = df

    def evict(self, c, s, name):
        self.stored.pop(name, None)


class _FakeMemory:
    def __init__(self):
        self.verified_facts = {}


def _context(sandbox=None, memory=None):
    return {
        "sandbox": sandbox or _FakeSandbox(),
        "session_dataframes": {},
        "session_id": "s1",
        "client_id": "c1",
        "session_manager": _FakeSM(),
        "memory": memory or _FakeMemory(),
    }


async def test_run_no_code():
    tool = PythonTool()
    result = await tool.run({"code": ""}, _context())
    assert "error" in result.data
    assert "No code" in result.data["error"]


async def test_run_validation_error():
    tool = PythonTool()
    result = await tool.run({"code": "import os"}, _context())
    assert result.data["status"] == "error"
    assert "not allowed" in result.data["error"]


async def test_run_no_sandbox():
    tool = PythonTool()
    ctx = _context()
    ctx["sandbox"] = None
    result = await tool.run({"code": "x = 1"}, ctx)
    assert "error" in result.data
    assert "Sandbox not initialized" in result.data["error"]


async def test_run_success():
    tool = PythonTool()
    sb = _FakeSandbox(output="hello", data={})
    result = await tool.run({"code": "print('hello')", "action": "greet"}, _context(sandbox=sb))
    assert result.data["status"] == "success"
    assert result.ui_data.get("output") == "hello"


async def test_run_with_output():
    tool = PythonTool()
    sb = _FakeSandbox(output="line1\nline2", data={})
    result = await tool.run({"code": "print('line1')\nprint('line2')"}, _context(sandbox=sb))
    assert result.ui_data["output"] == "line1\nline2"


async def test_run_extracts_facts():
    tool = PythonTool()
    sb = _FakeSandbox(output="FACT: key=value\nFACT: note", data={})
    mem = _FakeMemory()
    result = await tool.run({"code": "print('FACT: key=value')"}, _context(sandbox=sb, memory=mem))
    assert result.data["status"] == "success"
    assert mem.verified_facts.get("key") == "value"
    assert "note" in str(mem.verified_facts)


async def test_run_sandbox_error():
    tool = PythonTool()
    sb = _FakeSandbox(error="NameError: x not defined", output="", data={})
    result = await tool.run({"code": "print(x)"}, _context(sandbox=sb))
    assert result.data["status"] == "error"
    assert "NameError" in result.ui_data["error"]


async def test_run_partial_with_output_and_error():
    tool = PythonTool()
    sb = _FakeSandbox(output="partial output", error="some error", data={})
    result = await tool.run({"code": "print('partial')\nraise ValueError()"}, _context(sandbox=sb))
    assert result.data["status"] == "partial"
    assert result.data["partial_reason"] == "output_with_error"


async def test_run_output_truncated():
    tool = PythonTool()
    sb = _FakeSandbox(output="x" * 25000, data={})
    result = await tool.run({"code": "print('x'*25000)"}, _context(sandbox=sb))
    assert result.data["status"] == "error"
    assert result.data["output_truncated"] is True
    assert "too long" in result.data["error"].lower()


async def test_run_returns_dataframe():
    tool = PythonTool()
    df = pd.DataFrame({"a": [1, 2]})
    parquet_bytes = df.to_parquet(index=False)
    import base64
    b64 = base64.b64encode(parquet_bytes).decode()
    sb = _FakeSandbox(output="ok", data={"df1": b64})
    sm = _FakeSM()
    ctx = _context(sandbox=sb)
    ctx["session_manager"] = sm
    result = await tool.run({"code": "df1 = pd.DataFrame({'a':[1,2]})"}, ctx)
    assert result.data["status"] == "success"
    assert "df1" in result.data.get("returned_dfs", [])
    assert "df1" in ctx["session_dataframes"]


async def test_run_error_does_not_overwrite_preexisting_df():
    tool = PythonTool()
    original = pd.DataFrame({"good": [1]})
    sm = _FakeSM()
    sm.stored["existing"] = original

    import base64
    replacement = pd.DataFrame({"bad": [9]})
    b64 = base64.b64encode(replacement.to_parquet(index=False)).decode()
    sb = _FakeSandbox(output="", error="boom", data={"existing": b64, "new": b64})
    ctx = _context(sandbox=sb)
    ctx["session_manager"] = sm

    result = await tool.run({"code": "existing = replacement"}, ctx)
    assert result.data["status"] == "error"
    assert {"existing", "new"} <= set(result.data.get("returned_dfs", []))
    assert sm.stored["existing"].equals(original)
    assert "new" in sm.stored


async def test_run_sandbox_malformed_result():
    class _BadSandbox:
        async def execute(self, code, session_id=None, dataframes=None):
            return "not a dict"

    tool = PythonTool()
    result = await tool.run({"code": "x=1"}, _context(sandbox=_BadSandbox()))
    assert "error" in result.data


# ── Truncation warning tests ──────────────────────────────────────────────────


def _truncated_context(truncated_names=None, memory=None):
    dfs = {}
    for name in (truncated_names or []):
        dfs[name] = {"shape": [100, 3], "columns": ["a", "b", "c"], "truncated": True}
    dfs["full_df"] = {"shape": [50, 2], "columns": ["x", "y"], "truncated": False}
    ctx = _context(memory=memory)
    ctx["session_dataframes"] = dfs
    return ctx


async def test_run_warns_truncated_single():
    tool = PythonTool()
    sb = _FakeSandbox(output="FACT: metric = 100", data={})
    mem = _FakeMemory()
    result = await tool.run({"code": "print('FACT: metric = 100')", "action": "check"}, _truncated_context(["df1"], memory=mem))
    assert result.data["status"] == "success"
    assert "TRUNCATED" in result.data.get("output", "")
    assert "df1" in result.data["output"]
    assert "metric" not in mem.verified_facts


async def test_run_warns_truncated_multiple():
    tool = PythonTool()
    sb = _FakeSandbox(output="FACT: x=1", data={})
    mem = _FakeMemory()
    result = await tool.run({"code": "print('FACT: x=1')", "action": "test"}, _truncated_context(["df1", "df2"], memory=mem))
    assert result.data["status"] == "success"
    assert "TRUNCATED" in result.data.get("output", "")
    assert "df1" in result.data["output"]
    assert "df2" in result.data["output"]
    assert "x" not in mem.verified_facts


async def test_run_no_warning_no_truncation():
    tool = PythonTool()
    sb = _FakeSandbox(output="no warning expected", data={})
    ctx = _truncated_context([])
    # Ensure no DF is truncated
    for v in ctx["session_dataframes"].values():
        v["truncated"] = False
    result = await tool.run({"code": "print('hi')", "action": "test"}, ctx)
    assert result.data["status"] == "success"
    assert "TRUNCATED" not in result.data.get("output", "")


async def test_run_fact_captured_no_truncation():
    tool = PythonTool()
    sb = _FakeSandbox(output="FACT: total = 500", data={})
    mem = _FakeMemory()
    ctx = _truncated_context(memory=mem)
    ctx["sandbox"] = sb
    result = await tool.run({"code": "print('FACT: total = 500')"}, ctx)
    assert result.data["status"] == "success"
    assert "FACT: total = 500" in result.data.get("output", "")
    assert mem.verified_facts.get("total") == "500"


async def test_run_fact_skipped_when_truncated():
    tool = PythonTool()
    sb = _FakeSandbox(output="FACT: bad = 0", data={})
    mem = _FakeMemory()
    result = await tool.run({"code": "print('FACT: bad = 0')"}, _truncated_context(["df1"], memory=mem))
    assert result.data["status"] == "success"
    assert "TRUNCATED" in result.data.get("output", "")
    assert "bad" not in mem.verified_facts


# ── Business context formatting tests ─────────────────────────────────────────


def test_business_context_sorts_by_title():
    from agent.core import Agent
    entries = [
        {"title": "Zebra", "content": "striped", "is_active": True},
        {"title": "Alpha", "content": "first", "is_active": True},
        {"title": "Beta", "content": "second", "is_active": True},
    ]
    result = Agent._format_business_context(entries)
    alpha = result.index("- Alpha:")
    beta = result.index("- Beta:")
    zebra = result.index("- Zebra:")
    assert alpha < beta < zebra


def test_business_context_includes_all_active():
    from agent.core import Agent
    entries = [{"title": f"T{i}", "content": f"def{i}", "is_active": True} for i in range(25)]
    result = Agent._format_business_context(entries)
    for i in range(25):
        assert f"- T{i}:" in result


def test_business_context_skips_inactive():
    from agent.core import Agent
    entries = [
        {"title": "Active", "content": "yes", "is_active": True},
        {"title": "Inactive", "content": "no", "is_active": False},
    ]
    result = Agent._format_business_context(entries)
    assert "Active" in result
    assert "Inactive" not in result


# ── Chart partiality metadata tests ────────────────────────────────────────────


def _make_chart_df():
    import pandas as pd
    return pd.DataFrame({
        "category": [f"cat{i}" for i in range(30)],
        "value": list(range(30)),
    })


def test_chart_meta_cap_categories_rollup():
    from agent.tools.chart import _chart_cap_categories
    df = _make_chart_df()
    _, warnings, rollup = _chart_cap_categories(df, "category", ["value"])
    assert rollup > 0
    assert len(warnings) == 1
    assert "Other" in warnings[0]


def test_chart_meta_cap_categories_no_rollup():
    from agent.tools.chart import _chart_cap_categories
    df = _make_chart_df().head(5)
    _, warnings, rollup = _chart_cap_categories(df, "category", ["value"])
    assert rollup == 0
    assert len(warnings) == 0


def test_chart_meta_sort_limit_limited():
    from agent.tools.chart import _chart_sort_and_limit
    df = _make_chart_df()
    _, warnings, limited = _chart_sort_and_limit(df, "category", ["value"], None, "desc", 5)
    assert limited is True
    assert len(warnings) == 1
    assert "Limited" in warnings[0]


def test_chart_meta_sort_limit_not_limited():
    from agent.tools.chart import _chart_sort_and_limit
    df = _make_chart_df().head(5)
    _, warnings, limited = _chart_sort_and_limit(df, "category", ["value"], None, "desc", None)
    assert limited is False
    assert len(warnings) == 0
