"""Tests for agent.context — Context message assembly, budgeting, compaction,
summarization, and the durable ContextMemory ledger.
"""
from agent.context import Context, TokenCalibrator


def _msg(role, content):
    return {"role": role, "content": content}


def _calibrated(density=0.4):
    cal = TokenCalibrator()
    cal.record_real_usage(prompt_tokens=4000, sent_chars=10000)
    assert abs(cal.tokens_per_char - density) < 1e-9
    return cal


def test_calibrator_uncalibrated_estimates_zero():
    cal = TokenCalibrator()
    assert not cal.calibrated
    assert cal.estimate(_msg("user", "x" * 1000)) == 0
    assert cal.estimate_many([_msg("user", "a"), _msg("assistant", "b")]) == 0


def test_calibrator_uses_real_usage():
    cal = TokenCalibrator()
    # first measurement seeds the density exactly
    cal.record_real_usage(prompt_tokens=250, sent_chars=1000)
    assert cal.calibrated
    # estimate is chars(json.dumps) * density — dumps adds quotes, so ~1028 * 0.25
    import json as _json
    assert cal.estimate(_msg("user", "y" * 1000)) == int(len(_json.dumps(_msg("user", "y" * 1000))) * 0.25)
    # EMA blends subsequent measurements (0.7 * 0.25 + 0.3 * 0.5 = 0.325)
    cal.record_real_usage(prompt_tokens=500, sent_chars=1000)
    assert abs(cal.tokens_per_char - 0.325) < 1e-9
    # zero/negative measurements are ignored
    cal.record_real_usage(0, 1000)
    assert abs(cal.tokens_per_char - 0.325) < 1e-9


def test_calibrator_snapshot_restore():
    cal = TokenCalibrator()
    cal.record_real_usage(prompt_tokens=250, sent_chars=1000)
    restored = TokenCalibrator()
    restored.restore(cal.snapshot())
    assert restored.calibrated
    assert abs(restored.tokens_per_char - cal.tokens_per_char) < 1e-9
    empty = TokenCalibrator()
    empty.restore(None)
    assert not empty.calibrated


def test_context_records_real_usage():
    ctx = Context(system_prompt="sys", user_content="q")
    assert not ctx.calibrator.calibrated
    ctx.record_real_usage(prompt_tokens=120, sent_messages=ctx.messages)
    assert ctx.calibrator.calibrated
    assert ctx.estimated_tokens() > 0


def test_append_tool_result_strips_none():
    ctx = Context(system_prompt="sys", user_content="q")
    t = ctx.append_tool_result("call_1", {"status": "ok", "null_field": None})
    assert t["role"] == "tool"
    assert t["tool_call_id"] == "call_1"
    assert '"status"' in t["content"]
    assert "null" not in t["content"]


def test_append_assistant_sanitizes_bad_tool_args():
    ctx = Context(system_prompt="sys", user_content="q")
    bad_calls = [{
        "id": "c1",
        "type": "function",
        "function": {"name": "execute_sql", "arguments": "{not valid json"},
    }]
    msg = ctx.append_assistant("", bad_calls)
    # raw invalid JSON passes through (no silent sanitization)
    assert msg["tool_calls"][0]["function"]["arguments"] == "{not valid json"
    # valid args pass through untouched
    good_calls = [{
        "id": "c2",
        "type": "function",
        "function": {"name": "run_python", "arguments": '{"code": "x=1"}'},
    }]
    msg2 = ctx.append_assistant("", good_calls)
    assert msg2["tool_calls"][0]["function"]["arguments"] == '{"code": "x=1"}'


def test_upsert_reference_and_dataframes():
    ctx = Context(system_prompt="sys", user_content="q")
    ctx.upsert_reference({"tables": ["client"]})
    assert ctx.reference_data_msg is not None
    assert "[DATABASE REFERENCE]" in ctx.reference_data_msg["content"]
    assert ctx.reference_data_msg["role"] == "user"
    ctx.upsert_available_dataframes({"df1": {}, "df2": {}})
    assert ctx.available_dataframes_msg is not None
    assert "[AVAILABLE DATAFRAMES]" in ctx.available_dataframes_msg["content"]
    assert '"available_dataframes": ["df1", "df2"]' in ctx.available_dataframes_msg["content"]


def test_flatten_tool_rounds():
    msgs = [
        _msg("system", "sys"),
        _msg("user", "q"),
        {"role": "assistant", "tool_calls": [
            {"id": "c1", "function": {"name": "execute_sql", "arguments": "{}"}},
        ]},
        {"role": "tool", "tool_call_id": "c1", "content": "result a"},
        {"role": "tool", "tool_call_id": "c1", "content": "result b"},
        _msg("assistant", "final answer"),
    ]
    flat = Context.flatten_tool_rounds(msgs)
    # system + user + flattened assistant + final assistant
    assert len(flat) == 4
    assert flat[2]["role"] == "assistant"
    assert "[execute_sql result]" in flat[2]["content"]
    assert "result a" in flat[2]["content"]
    assert "result b" in flat[2]["content"]
    # plain assistant message is preserved verbatim
    assert flat[3] == _msg("assistant", "final answer")


def test_trim_uncalibrated_keeps_everything():
    # N+1 model: before the first real measurement, nothing is trimmed.
    msgs = [_msg("system", "s"), _msg("user", "orig"), _msg("assistant", "x" * 1000)]
    kept = Context._trim_to_budget(msgs, max_tokens=10, calibrator=TokenCalibrator())
    assert kept == msgs


def test_trim_to_budget_keeps_system_and_original_query():
    msgs = [_msg("system", "s"), _msg("user", "orig")]
    # two droppable plain assistant messages; the newest is always kept
    msgs.append(_msg("assistant", "x" * 1000))
    msgs.append(_msg("assistant", "y" * 1000))
    kept = Context._trim_to_budget(msgs, max_tokens=10, calibrator=_calibrated())
    # system + original query always kept
    assert kept[0] == msgs[0]
    assert kept[1] == msgs[1]
    # the newest droppable message survives (it's the newest block)
    assert _msg("assistant", "y" * 1000) in kept
    # the older one is dropped
    assert _msg("assistant", "x" * 1000) not in kept


def test_trim_to_budget_keeps_newest_round():
    msgs = [
        _msg("system", "s"),
        _msg("user", "orig"),
        {"role": "assistant", "tool_calls": [{"id": "c1", "function": {"name": "execute_sql", "arguments": "{}"}}]},
        {"role": "tool", "tool_call_id": "c1", "content": "r1" * 500},
        {"role": "assistant", "tool_calls": [{"id": "c2", "function": {"name": "execute_sql", "arguments": "{}"}}]},
        {"role": "tool", "tool_call_id": "c2", "content": "r2" * 500},
    ]
    # tiny budget -> only the newest round (last two entries) fits plus foundation
    kept = Context._trim_to_budget(msgs, max_tokens=10, calibrator=_calibrated())
    assert _msg("system", "s") in kept
    # newest round is preserved (its tool result content contains r2)
    assert any("r2r2r2" in m.get("content", "") for m in kept if m.get("role") == "tool")
    ctx = Context(system_prompt="sys", user_content="q")
    ctx.messages.append(_msg("assistant", "answer"))
    ctx.upsert_reference({"x": 1})
    step_context = ctx._build_step_context(1, 5, available_dataframes=["df1"])
    result = ctx.build_request(max_tokens=100000, step_context=step_context, progress_notes=["note 1"])
    # foundation + answer + reference + step context tail (dataframes live inside step context)
    assert result[0]["role"] == "system"
    assert result[1]["role"] == "user"
    ref_idx = [i for i, m in enumerate(result) if "[DATABASE REFERENCE]" in m.get("content", "")]
    assert len(ref_idx) == 1
    # reference is kept right after system so its stable tokens are prefix-cached
    assert ref_idx[0] == 1
    assert ref_idx[0] < result.index(_msg("assistant", "answer"))
    tail = result[-1]
    assert "[STEP CONTEXT]" in tail["content"]
    assert "note 1" in tail["content"]
    # [AVAILABLE DATAFRAMES] is no longer a separate prefix message — it's embedded in step context
    assert not any("[AVAILABLE DATAFRAMES]" in m.get("content", "") for m in result)
    assert '"available_dataframes"' in tail["content"]


def test_compact_does_not_duplicate_reference_on_resume():
    ctx = Context(system_prompt="sys", user_content="q")
    ctx.upsert_reference({"x": 1})
    ctx.messages.append(_msg("assistant", "answer"))
    ctx.compact(max_tokens=100000)
    # the compacted messages no longer embed the reference
    assert not any("[DATABASE REFERENCE]" in m.get("content", "") for m in ctx.messages)
    # a resumed turn must emit the reference exactly once, right after system
    step_context = ctx._build_step_context(1, 5)
    result = ctx.build_request(max_tokens=100000, step_context=step_context)
    ref_idx = [i for i, m in enumerate(result) if "[DATABASE REFERENCE]" in m.get("content", "")]
    assert len(ref_idx) == 1
    assert ref_idx[0] == 1
    assert ref_idx[0] < result.index(_msg("assistant", "answer"))


def test_compact_flattens_and_trims():
    ctx = Context(system_prompt="sys", user_content="q")
    ctx.messages.append({"role": "assistant", "tool_calls": [{"id": "c1", "function": {"name": "execute_sql", "arguments": "{}"}}]})
    ctx.messages.append(_msg("tool", "big result " * 50))
    ctx.messages.append(_msg("assistant", "final"))
    ctx.messages.append(_msg("system", "Step limit reached. No tools exist for this message."))
    ctx.compact(max_tokens=100000)
    # tool round is flattened into a single assistant message
    assert not any(m.get("role") == "tool" for m in ctx.messages)
    # the "step limit reached" system note is removed
    assert not any("Step limit reached" in m.get("content", "") for m in ctx.messages if m.get("role") == "system")


def test_step_context_renders_dataframe_columns_from_metadata():
    ctx = Context(system_prompt="sys", user_content="q")
    step_context = ctx._build_step_context(1, 5, available_dataframes={
        "df1": {"columns": ["a", "b"], "truncated": False},
        "df2": {"columns": ["x"], "truncated": True},
        "df3": {},
    })
    assert step_context["available_dataframes"] == ["df1 [a, b]", "df2 (TRUNCATED) [x]", "df3"]
    # list input stays verbatim (backward compatible)
    assert ctx._build_step_context(1, 5, available_dataframes=["df1"])["available_dataframes"] == ["df1"]


def test_build_request_step_context_includes_columns():
    ctx = Context(system_prompt="sys", user_content="q")
    step_context = ctx._build_step_context(1, 5, available_dataframes={"df1": {"columns": ["a", "b"]}})
    result = ctx.build_request(max_tokens=100000, step_context=step_context)
    assert '"df1 [a, b]"' in result[-1]["content"]


def test_summarize_replaces_middle():
    ctx = Context(system_prompt="sys", user_content="orig query")
    ctx.messages.append(_msg("assistant", "intermediate answer"))
    ctx.messages.append(_msg("user", "follow-up question"))
    ctx.messages.append(_msg("assistant", "last answer"))
    ctx.summarize("SUMMARY TEXT")
    # foundation (system + original query) preserved
    assert ctx.messages[0] == {"role": "system", "content": "sys", "_kind": "system"}
    assert ctx.messages[1] == {"role": "user", "content": "orig query", "_kind": "user_query"}
    # middle replaced by single summary message
    assert "[CONTEXT SUMMARY]" in ctx.messages[2]["content"]
    assert "SUMMARY TEXT" in ctx.messages[2]["content"]
    # the follow-up user message AND the final assistant answer are preserved
    assert _msg("user", "follow-up question") in ctx.messages
    assert _msg("assistant", "last answer") in ctx.messages


def test_summarize_noop_when_too_short():
    ctx = Context(system_prompt="sys", user_content="orig")
    before = list(ctx.messages)
    ctx.summarize("x")
    assert ctx.messages == before


def test_log_summary_counts_roles():
    ctx = Context(system_prompt="sys", user_content="q")
    ctx.messages.append(_msg("assistant", "a"))
    ctx.messages.append({"role": "assistant", "tool_calls": [{"id": "c", "function": {"name": "x", "arguments": "{}"}}]})
    ctx.messages.append(_msg("tool", "r"))
    ctx.upsert_reference({"x": 1})
    summary = ctx.log_summary()
    assert summary["by_role"]["system"] == 1
    assert summary["by_role"]["user"] == 1
    assert summary["by_role"]["assistant_final"] == 1
    assert summary["by_role"]["assistant_tool_call"] == 1
    assert summary["by_role"]["tool"] == 1
    assert summary["reference_present"] is True
    assert summary["message_count"] == 5


def test_step_context_truncates_entries():
    ctx = object.__new__(Context)
    ctx.tool_trail = ["x" * 1000]
    ctx.recent_errors = ["e" * 1000]
    ctx.verified_facts = {}
    sc = ctx._build_step_context(1, 5)
    assert len(sc["tool_trail"][0]) <= 300
    assert len(sc["recent_errors"][0]) <= 500
    assert sc["progress"]["max_steps"] == 5


# --- Memory ledger ---------------------------------------------------------


def test_log_error_dedup_and_cap():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    ctx.log_error("execute_sql", "boom")
    ctx.log_error("execute_sql", "boom")  # duplicate -> ignored
    assert len(ctx.recent_errors) == 1
    for i in range(20):
        ctx.log_error("execute_sql", f"err{i}")
    assert len(ctx.recent_errors) <= 5  # RECENT_ERRORS_MAX cap


def test_log_sql_formats_trail_and_tables():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.tables_touched = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    ctx.log_sql([{"df_name": "df_a"}], ["client", "reservation"], status="success")
    assert any("SQL:success" in e for e in ctx.tool_trail)
    assert "client" in ctx.tables_touched
    assert "reservation" in ctx.tables_touched


def test_log_sql_error_path():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.tables_touched = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    ctx.log_sql([], [], status="error", error="syntax near FROM")
    assert any("execute_sql failed" in e for e in ctx.recent_errors)


def test_log_python_formatting():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.tables_touched = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    ctx.log_python("x = 1", output_excerpt="done", status="success")
    assert any("Py:" in e and "done" in e for e in ctx.tool_trail)
    ctx.log_python("y = 2", error="NameError: y", status="error")
    assert any("failed" in e for e in ctx.recent_errors)


def test_tables_touched_first_seen_order():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.tables_touched = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    # log a small set so we stay under MAX_REFERENCED_TABLES
    for i in range(5):
        ctx.log_sql([], [f"table_{i}"], status="success")
    # first-referenced table is first (stable append-only order, so the
    # [DATABASE REFERENCE] keep-tier prefix stays byte-identical across
    # steps and provider prompt-cache hits are preserved)
    assert ctx.tables_touched[0] == "table_0"
    # re-referencing an existing table does NOT move it
    ctx.log_sql([], ["table_1"], status="success")
    assert ctx.tables_touched[0] == "table_0"
    assert ctx.tables_touched == ["table_0", "table_1", "table_2", "table_3", "table_4"]
    # capped at MAX_REFERENCED_TABLES (20)
    for i in range(30):
        ctx.log_sql([], [f"wide_{i}"], status="success")
    assert len(ctx.tables_touched) <= 20


def test_tool_trail_cap():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.tables_touched = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    for i in range(60):
        ctx.log_sql([], [], status="success")
    assert len(ctx.tool_trail) <= 40  # MAX_CODE_LOG_ENTRIES


def test_log_describe():
    ctx = object.__new__(Context)
    ctx.tool_trail = []
    ctx.tables_touched = []
    ctx.recent_errors = []
    ctx.verified_facts = {}
    ctx.log_describe(["client", "reservation"])
    assert any("D:" in e for e in ctx.tool_trail)
    assert "client" in ctx.tables_touched


def test_build_available_dataframes_truncated_annotation():
    ctx = Context(system_prompt="sys", user_content="q")
    dfs = {
        "clean": {"truncated": False},
        "chopped": {"truncated": True},
        "plain": {},
    }
    result = ctx.build_available_dataframes(dfs)
    names = result["available_dataframes"]
    assert "clean" in names
    assert "chopped (TRUNCATED)" in names
    assert "plain" in names
    assert all("(TRUNCATED)" not in n for n in names if n != "chopped (TRUNCATED)")

