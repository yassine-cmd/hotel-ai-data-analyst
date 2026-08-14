"""Tests for agent.core.Agent helper methods — reference/schema building,
progress notes, tool-arg parsing, tool-call leakage stripping, and the
fresh-session factory.
"""
from agent.step_loop import _strip_tool_sentinels, _collect_questions, _format_questions_as_text
from agent.core import Agent
from agent.context import Context
from agent.db import Budget


class _FakeExecutor:
    def __init__(self, schema_dict=None):
        self.schema_dict = schema_dict or {}

    async def execute(self, sql, *, datasource_id="test", max_rows=10000, timeout_ms=15000, query_id=None):
        from agent.db import QueryResult, ColumnMeta
        return QueryResult(query_id="test", columns=[], rows=[], row_count=0)

    async def close(self):
        pass


def _guard_stub_schema():
    return {
        "client": {"columns": [{"name": "id", "type": "INT", "key": "PRI"}, {"name": "ssn", "type": "VARCHAR", "key": ""}]},
        "reservation": {"columns": [{"name": "id", "type": "INT", "key": "PRI"}, {"name": "amount", "type": "DECIMAL", "key": ""}]},
    }


def _make_agent():
    return Agent(executor=_FakeExecutor(), sandbox=None, llm=None)


def test_build_reference_with_guard():
    from agent.tools.guard import SensitiveDataGuard
    agent = _make_agent()
    guard = SensitiveDataGuard(lambda: _guard_stub_schema())
    agent.set_guard(guard)
    ref = agent._build_reference(["client", "reservation"], guard=guard, schema_dict=_guard_stub_schema())
    assert "sensitive_tables" in ref
    # accessible columns are reported for referenced tables
    assert "client" in ref["accessible_columns"]
    assert isinstance(ref["accessible_columns"]["client"], list)
    assert "table_ddl" in ref


def test_build_reference_no_caps():
    from agent.tools.guard import SensitiveDataGuard
    agent = _make_agent()
    schema = {f"t{i}": {"columns": [{"name": "c", "type": "INT", "key": ""}]} for i in range(30)}
    guard = SensitiveDataGuard(lambda: schema)
    agent.set_guard(guard)
    ref = agent._build_reference([f"t{i}" for i in range(30)], guard=guard, schema_dict=schema)
    # every referenced table gets its columns and DDL — no truncation
    assert len(ref.get("accessible_columns", {})) == 30
    assert len(ref["table_ddl"].split("\n\n")) == 30


def test_convergence_notes_half_step():
    agent = _make_agent()
    agent.max_steps = 10
    ctx = object.__new__(Context)
    ctx.recent_errors = []
    notes = agent._convergence_notes(ctx, 5)
    assert any("5/10" in n for n in notes)


def test_convergence_notes_final_steps():
    agent = _make_agent()
    agent.max_steps = 10
    ctx = object.__new__(Context)
    ctx.recent_errors = []
    notes = agent._convergence_notes(ctx, 8)
    assert any("Final steps" in n for n in notes)


def test_convergence_notes_last_step():
    agent = _make_agent()
    agent.max_steps = 10
    ctx = object.__new__(Context)
    ctx.recent_errors = []
    notes = agent._convergence_notes(ctx, 9)
    assert any("last step" in n.lower() for n in notes)


def test_error_event_factory_forwards_envelope_fields():
    from agent.events import error as ev_error

    ev = ev_error("boom", code="PROVIDER_ERROR", retryable=True, detail="trace")
    assert ev["type"] == "error"
    assert ev["message"] == "boom"
    assert ev["code"] == "PROVIDER_ERROR"
    assert ev["retryable"] is True
    assert ev["detail"] == "trace"

    bare = ev_error("boom")
    assert bare == {"type": "error", "message": "boom"}


def test_convergence_notes_repeat_error():
    agent = _make_agent()
    agent.max_steps = 10
    ctx = object.__new__(Context)
    ctx.recent_errors = ["boom", "boom"]
    notes = agent._convergence_notes(ctx, 1)
    assert any("repeating the same error" in n for n in notes)


def test_convergence_notes_no_repeat_no_note():
    """When recent_errors has unique entries, no repeat-error note appears."""
    agent = _make_agent()
    agent.max_steps = 10
    ctx = object.__new__(Context)
    ctx.recent_errors = ["first", "second"]
    notes = agent._convergence_notes(ctx, 1)
    assert not any("repeating the same error" in n for n in notes)


def test_parse_tool_args():
    assert Agent._parse_tool_args('{"a": 1}') == {"a": 1}
    assert Agent._parse_tool_args("") == {}
    assert Agent._parse_tool_args("{bad json") is None


def test_schema_index():
    idx = Agent._schema_index({"client": {"description": "people"}, "reservation": {"is_sensitive": True}})
    assert "client (0 cols) — people" in idx
    assert "reservation" in idx
    assert "[SENSITIVE/BLOCKED]" in idx


def test_schema_index_row_count():
    idx = Agent._schema_index({
        "hotels": {"row_count": 85, "columns": [{"name": "id"}], "description": "Hotel properties"},
        "big_table": {"row_count": 1_250_000, "columns": [{"name": "a"}, {"name": "b"}]},
        "no_counts": {"columns": [{"name": "x"}]},
    })
    assert "hotels (85 rows, 1 cols) — Hotel properties" in idx
    assert "big_table (1,250,000 rows, 2 cols)" in idx
    assert "no_counts (1 cols)" in idx


def test_strip_tool_sentinels_clean():
    assert _strip_tool_sentinels("plain prose here") == "plain prose here"


def test_strip_tool_sentinels_salvages():
    text = ("This is a sufficiently long clean answer that exceeds the salvage "
            "threshold of forty characters before any sentinel marker. "
            "<｜tool▁calls▁begin｜> bad leaked part")
    out = _strip_tool_sentinels(text)
    assert "sufficiently long clean answer" in out
    assert "tool▁calls" not in out


def test_strip_tool_sentinels_short_salvage_dropped():
    text = "tiny <|tool_call|> leaked"
    out = _strip_tool_sentinels(text)
    # salvage too short -> empty
    assert out == ""


def test_strip_tool_sentinels_chatml_tool_call():
    # A leaked <|tool_call|> ChatML sentinel is truncated at the marker, and
    # the leading prose (longer than the 40-char salvage floor) is kept.
    text = ("This answer is long enough to survive the salvage floor of forty "
            "characters before the leaked <|tool_call|> marker appears here.")
    out = _strip_tool_sentinels(text)
    assert "tool_call" not in out
    assert out.startswith("This answer is long enough")


def test_strip_tool_sentinels_fullwidth_pipe():
    text = "answer text here ｜ leaked"
    out = _strip_tool_sentinels(text)
    assert "｜" not in out


def test_collect_and_format_questions():
    tool_calls = [{
        "function": {
            "name": "question",
            "arguments": '{"questions": [{"question": "When?", "options": ["a", "b"]}]}',
        }
    }]
    qs = _collect_questions(tool_calls)
    assert len(qs) == 1
    text = _format_questions_as_text(qs)
    assert "When?" in text
    assert "a; b" in text


def test_fresh_session_state():
    from config import get_settings
    agent = _make_agent()
    ctx, tracker, budget = agent._fresh_session_state(get_settings(), "schema idx", "my query")
    assert isinstance(ctx, Context)
    assert isinstance(budget, Budget)
    assert ctx.messages[0]["role"] == "system"
    assert "my query" in ctx.messages[1]["content"]
    # Token counter always starts at zero
    assert tracker.total_tokens == 0


def test_fresh_session_state_with_business_context():
    from config import get_settings
    agent = _make_agent()
    entries = [
        {"title": "ADR", "content": "Average Daily Rate", "is_active": True},
        {"title": "RevPAR", "content": "Revenue Per Available Room", "is_active": True},
    ]
    ctx, tracker, budget = agent._fresh_session_state(get_settings(), "schema idx", "my query", business_context=entries)
    prompt = ctx.messages[0]["content"]
    assert "[BUSINESS CONTEXT]" in prompt
    assert "ADR" in prompt
    assert "RevPAR" in prompt
    assert "[/BUSINESS CONTEXT]" in prompt


def test_fresh_session_state_excludes_inactive():
    from config import get_settings
    agent = _make_agent()
    entries = [
        {"title": "ADR", "content": "Average Daily Rate", "is_active": True},
        {"title": "OldTerm", "content": "Deprecated", "is_active": False},
    ]
    ctx, tracker, budget = agent._fresh_session_state(get_settings(), "schema idx", "my query", business_context=entries)
    prompt = ctx.messages[0]["content"]
    assert "[BUSINESS CONTEXT]" in prompt
    assert "ADR" in prompt
    assert "OldTerm" not in prompt


def test_fresh_session_state_empty_business_context_omitted():
    from config import get_settings
    agent = _make_agent()
    ctx, tracker, budget = agent._fresh_session_state(get_settings(), "schema idx", "my query", business_context=[])
    prompt = ctx.messages[0]["content"]
    assert "[BUSINESS CONTEXT]" not in prompt


def test_system_prompt_placeholders_resolved():
    from agent.core import SYSTEM_PROMPT
    from config import get_settings
    s = get_settings()
    for ph in ("{PKGS}", "{BANNED}", "{STEPS}", "{DATAFRAMES_MAX}", "{CURRENCY}", "{DATA_SINCE}"):
        assert ph not in SYSTEM_PROMPT, f"unresolved placeholder {ph}"
    assert str(s.SESSION_DATAFRAMES_MAX) in SYSTEM_PROMPT
    assert s.CURRENCY in SYSTEM_PROMPT
    assert s.DATA_SINCE in SYSTEM_PROMPT
    assert "[ENVIRONMENT FACTS]" in SYSTEM_PROMPT


def test_system_prompt_cache_ordering():
    """Stable rules precede variable blocks so the cached prefix stays intact:
    immutable rules -> [ENVIRONMENT FACTS] -> per-client [BUSINESS CONTEXT]."""
    from agent.core import SYSTEM_PROMPT
    from config import get_settings
    agent = _make_agent()
    entries = [{"title": "ADR", "content": "Average Daily Rate", "is_active": True}]
    ctx, _, _ = agent._fresh_session_state(get_settings(), "schema idx", "my query", business_context=entries)
    prompt = ctx.messages[0]["content"]
    assert prompt.index("[ENVIRONMENT FACTS]") < prompt.index("[BUSINESS CONTEXT]")
    assert "[BUSINESS CONTEXT]" in prompt[prompt.index("[ENVIRONMENT FACTS]"):]


def test_format_business_context_includes_all_active():
    entries = [{"title": f"T{i}", "content": f"Def {i}", "is_active": True} for i in range(25)]
    block = Agent._format_business_context(entries)
    lines = block.strip().split("\n")
    term_lines = [l for l in lines if l.startswith("- T")]
    assert len(term_lines) == 25


def test_format_business_context_empty():
    assert Agent._format_business_context([]) == ""
    assert Agent._format_business_context(None) == ""


def test_format_business_context_excludes_scoped_entries():
    entries = [
        {"title": "ActiveNow", "content": "ok", "is_active": True},
        {"title": "ScopedAway", "content": "no", "is_active": True, "scope_table": "orders"},
    ]
    block = Agent._format_business_context(entries)
    assert "ActiveNow" in block
    assert "ScopedAway" not in block


def test_format_business_context_sorts_by_title():
    entries = [
        {"title": "Low", "content": "a", "is_active": True},
        {"title": "High", "content": "b", "is_active": True},
        {"title": "Mid", "content": "c", "is_active": True},
    ]
    block = Agent._format_business_context(entries)
    assert block.index("- High:") < block.index("- Low:") < block.index("- Mid:")


def test_format_business_context_marks_omitted_entries():
    entries = [
        {"title": "A", "content": "a" * 5000, "is_active": True},
        {"title": "Z", "content": "z" * 5000, "is_active": True},
    ]
    block = Agent._format_business_context(entries, max_chars=6000)
    assert "omitted" in block
    assert "z" * 5000 not in block
    assert "a" * 5000 in block
    assert block.endswith("[/BUSINESS CONTEXT]")


def test_format_business_context_no_marker_when_all_fit():
    entries = [{"title": "A", "content": "x", "is_active": True}] * 3
    block = Agent._format_business_context(entries, max_chars=6000)
    assert "omitted" not in block


def test_build_reference_injects_scoped_business_context():
    agent = _make_agent()
    entries = [
        {"title": "Staleness", "content": "Orders older than 30 days are stale.", "is_active": True, "scope_table": "orders"},
        {"title": "Note", "content": "Wholesale only.", "is_active": True},
    ]
    schema = {"orders": {"columns": [{"name": "id", "type": "int", "key": "PRI"}]}}
    ref = agent._build_reference(["orders"], schema_dict=schema, business_context=entries)
    assert "BIZ:" in ref["table_ddl"]
    assert "Staleness" in ref["table_ddl"]
    assert "Wholesale only." not in ref["table_ddl"]


def test_build_reference_scoped_context_matches_case_insensitively():
    agent = _make_agent()
    entries = [
        {"title": "Rule", "content": "Applies here.", "is_active": True, "scope_table": "ORDERS"},
    ]
    schema = {"orders": {"columns": [{"name": "id", "type": "int", "key": "PRI"}]}}
    ref = agent._build_reference(["orders"], schema_dict=schema, business_context=entries)
    assert "Rule" in ref["table_ddl"]


def test_build_reference_without_guard():
    agent = _make_agent()
    ref = agent._build_reference(["t1"], schema_dict={"t1": {"columns": [{"name": "id", "type": "int", "key": ""}]}})
    assert "table_ddl" in ref
    assert "TABLE: t1" in ref["table_ddl"]


def test_build_reference_sensitive_vs_permission_reasons():
    """Blocked tables must carry distinct, honest reasons: global sensitive
    (applies to all users) vs not-in-permissions (role-based). Both applied at
    once resolves to the sensitive reason."""
    from agent.tools.guard import SensitiveDataGuard

    agent = _make_agent()
    schema = {
        "client": {"is_sensitive": True, "columns": [{"name": "id", "type": "int", "key": "PRI"}]},
        "ledger": {"columns": [{"name": "id", "type": "int", "key": "PRI"}]},
    }
    guard = SensitiveDataGuard(
        lambda: schema,
        sensitive_tables=lambda: {"client"},
        sensitive_columns=lambda: {"*": []},
    )

    # permission-only block -> role reason
    ref = agent._build_reference(["ledger"], guard=guard, schema_dict=schema, unauthorized=["ledger"])
    assert ref["blocked_tables"]["ledger"]["reason"] == "Table is not granted to this user's permissions"
    # DDL must not leak for a permission-blocked table
    assert "table_ddl" not in ref

    # sensitive (even when also outside permissions) -> sensitive reason wins
    ref2 = agent._build_reference(["client"], guard=guard, schema_dict=schema, unauthorized=["client"])
    assert ref2["blocked_tables"]["client"]["reason"].startswith("Table holds regulated personal data")
    assert "table_ddl" not in ref2

    # admin: no unauthorized tables, but a sensitive table is still blocked
    ref3 = agent._build_reference(["client"], guard=guard, schema_dict=schema, unauthorized=[])
    assert ref3["blocked_tables"]["client"]["reason"].startswith("Table holds regulated personal data")


def test_build_reference_allows_granted_non_sensitive():
    from agent.tools.guard import SensitiveDataGuard

    agent = _make_agent()
    schema = {"ledger": {"columns": [{"name": "id", "type": "int", "key": "PRI"}]}}
    guard = SensitiveDataGuard(lambda: schema, sensitive_tables=lambda: set(), sensitive_columns=lambda: {"*": []})
    ref = agent._build_reference(["ledger"], guard=guard, schema_dict=schema, unauthorized=[])
    assert "blocked_tables" not in ref
    assert "table_ddl" in ref


def test_token_counter_log_step():
    from agent.core import TokenCounter
    from types import SimpleNamespace
    tc = TokenCounter(context_limit=1000)
    usage = SimpleNamespace(
        prompt_tokens=10, completion_tokens=20, total_tokens=30,
        completion_tokens_details=SimpleNamespace(reasoning_tokens=5),
        prompt_cache_hit_tokens=2, prompt_cache_miss_tokens=3,
    )
    tc.record_step(usage)
    assert tc.total_prompt == 10
    assert tc.total_tokens == 30
    assert tc.total_thinking == 5


async def test_analyze_happy_path():
    from agent.core import Agent
    from types import SimpleNamespace

    class _FakeLLM:
        async def stream_chat(self, messages, tools=None, tool_choice="auto", stop=None, session_id=None):
            from types import SimpleNamespace
            chunk = SimpleNamespace(
                choices=[SimpleNamespace(
                    delta=SimpleNamespace(content="final answer", tool_calls=None),
                    finish_reason="stop",
                )],
                usage=SimpleNamespace(
                    prompt_tokens=5, completion_tokens=5, total_tokens=10,
                    completion_tokens_details=SimpleNamespace(reasoning_tokens=0),
                    prompt_cache_hit_tokens=0, prompt_cache_miss_tokens=0,
                ),
            )
            yield chunk

    class _FakeSessionManager:
        async def get_or_create(self, *a, **kw):
            return {"turn_count": 0, "history": [], "context": None, "tracker": None, "dataframes": {}}
        async def commit_turn(self, *a, **kw):
            return 1
        async def delete(self, *a, **kw):
            pass

        @property
        def store(self):
            return self

        def evict(self, c, s, name):
            pass

        def size_of(self, c, s, name=None):
            return 0

    agent = Agent(executor=_FakeExecutor(), sandbox=_FakePool(), llm=_FakeLLM(),
                  session_manager=_FakeSessionManager(), asset_manager=None)
    agent._guard = None

    results = []
    async for event in agent.analyze("test query", "s1", "c1"):
        results.append(event)
    assert len(results) > 0
    assert any(e.get("type") == "done" for e in results)
    # Verify event sequence: first event is a status update
    assert results[0].get("type") in ("status", "think", "plan")
    # Final event should be "done"
    assert results[-1].get("type") == "done"


async def test_analyze_provider_error_yields_error_event():
    from agent.core import Agent
    from openai import APIError

    class _FailingLLM:
        async def stream_chat(self, messages, tools=None, tool_choice="auto", stop=None, session_id=None):
            import httpx
            req = httpx.Request("POST", "http://llm")
            raise APIError("Upstream error from DigitalOcean: stream failed", request=req, body=None)
            yield  # pragma: no cover — unreachable, makes this an async generator

    class _FakeSessionManager:
        async def get_or_create(self, *a, **kw):
            return {"turn_count": 0, "history": [], "context": None, "tracker": None, "dataframes": {}}
        async def commit_turn(self, *a, **kw):
            return 1
        async def delete(self, *a, **kw):
            pass

        @property
        def store(self):
            return self

        def evict(self, c, s, name):
            pass

        def size_of(self, c, s, name=None):
            return 0

    agent = Agent(executor=_FakeExecutor(), sandbox=_FakePool(), llm=_FailingLLM(),
                  session_manager=_FakeSessionManager(), asset_manager=None)
    agent._guard = None

    results = []
    async for event in agent.analyze("test query", "s1", "c1"):
        results.append(event)

    errors = [e for e in results if e.get("type") == "error"]
    assert len(errors) == 1
    assert errors[0]["code"] == "PROVIDER_ERROR"
    assert errors[0]["retryable"] is True
    assert "DigitalOcean" in errors[0]["detail"]
    # Stream still terminates cleanly with a done event.
    assert results[-1].get("type") == "done"


class _FakePool:
    async def execute(self, code, session_id=None, dataframes=None):
        return {"output": "ok", "data": {}, "status": "success"}

    async def close(self):
        pass


# ContextMemory is used directly in progress_notes tests above


def _make_fake_llm(content="final answer", summary_content="SUMMARY OUT"):
    from types import SimpleNamespace

    class _FakeLLM:
        async def stream_chat(self, messages, tools=None, tool_choice="auto", stop=None, session_id=None):
            out = summary_content if tools is None else content
            chunk = SimpleNamespace(
                choices=[SimpleNamespace(
                    delta=SimpleNamespace(content=out, tool_calls=None),
                    finish_reason="stop",
                )],
                usage=SimpleNamespace(
                    prompt_tokens=5, completion_tokens=5, total_tokens=10,
                    completion_tokens_details=SimpleNamespace(reasoning_tokens=0),
                    prompt_cache_hit_tokens=0, prompt_cache_miss_tokens=0,
                ),
            )
            yield chunk

    return _FakeLLM()


async def _collect_events(agent, query, session_id="s1", client_id="c1"):
    results = []

    async for event in agent.analyze(query, session_id, client_id):
        results.append(event)

    return results


def _resume_agent(tmp_path, **llm_kwargs):
    from agent.session_manager import SessionManager
    sm = SessionManager(base_dir=str(tmp_path), ttl_minutes=60)
    agent = Agent(executor=_FakeExecutor(), sandbox=_FakePool(),
                  llm=_make_fake_llm(**llm_kwargs), session_manager=sm,
                  asset_manager=None)
    agent._guard = None
    return agent, sm


# --- Session resume path: follow-up append, compact, persist, token deltas -


async def test_analyze_resume_roundtrip(tmp_path):
    agent, sm = _resume_agent(tmp_path)

    first = await _collect_events(agent, "first query", "s1", "c1")
    assert any(e.get("type") == "done" for e in first)

    # Force a disk resume: drop the in-memory entry so get_or_create reloads
    # the serialized context from session.json.
    sm._sessions.pop(sm._key("s1", "c1"))
    second = await _collect_events(agent, "second query", "s1", "c1")
    done = next(e for e in second if e.get("type") == "done")

    entry = sm._sessions[sm._key("s1", "c1")]
    assert entry["turn_count"] == 2

    ctx = entry["context"]
    assert isinstance(ctx, Context)
    # The follow-up was appended as a user_followup message
    followups = [m for m in ctx.messages if m.get("_kind") == "user_followup"]
    assert any("second query" in m["content"] for m in followups)
    # Calibrator density was serialized and restored -> still calibrated
    assert ctx.calibrator.calibrated
    # Per-turn deltas exclude the restored baseline (turn 1 = 10 tokens)
    assert done["meta"]["turn_tokens"] == 10
    assert done["meta"]["turn_prompt_tokens"] == 5
    assert done["meta"]["context_limit"] == 200000

    hist = entry["history"]
    assert [t["query"] for t in hist] == ["first query", "second query"]
    assert hist[1]["answer"] == "final answer"
    assert isinstance(hist[1]["thinking_per_step"], dict)


async def test_analyze_resume_does_not_summarize_under_threshold(tmp_path):
    agent, sm = _resume_agent(tmp_path)
    await _collect_events(agent, "first query", "s1", "c1")
    sm._sessions.pop(sm._key("s1", "c1"))
    second = await _collect_events(agent, "second query", "s1", "c1")
    assert not any(e.get("type") == "summary" for e in second)
    assert not any(e.get("type") == "phase" and e.get("phase") == "summarizing" for e in second)


async def test_analyze_resume_triggers_summarization_at_threshold(tmp_path, monkeypatch):
    from agent.core import get_settings as real_get_settings
    low = real_get_settings().model_copy(update={"CONTEXT_SUMMARIZE_THRESHOLD": 1})
    monkeypatch.setattr("agent.core.get_settings", lambda: low)

    agent, sm = _resume_agent(tmp_path, content="answer A", summary_content="SUMMARY FROM LLM")
    await _collect_events(agent, "first query", "s1", "c1")
    sm._sessions.pop(sm._key("s1", "c1"))

    second = await _collect_events(agent, "second query", "s1", "c1")
    assert any(e.get("type") == "phase" and e.get("phase") == "summarizing" for e in second)
    summaries = [e for e in second if e.get("type") == "summary"]
    assert len(summaries) == 1
    assert summaries[0]["content"] == "SUMMARY FROM LLM"
    assert summaries[0]["structured_state"]["referenced_tables"] == []

    ctx = sm._sessions[sm._key("s1", "c1")]["context"]
    kinds = [m.get("_kind") for m in ctx.messages]
    # The middle of the conversation was collapsed into a single summary msg
    assert "summary" in kinds
    assert any("[CONTEXT SUMMARY]" in m.get("content", "") for m in ctx.messages)
    # The latest follow-up is preserved after the summary
    assert any("second query" in m.get("content", "") for m in ctx.messages if m.get("_kind") == "user_followup")
