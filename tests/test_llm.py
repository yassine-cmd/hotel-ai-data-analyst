from agent.llm import LLM


class _FakeChunk:
    def __init__(self, delta_text="", finish_reason=None, usage=None):
        self.choices = [_FakeChoice(delta_text, finish_reason)]
        self.usage = usage


class _FakeChoice:
    def __init__(self, delta_text="", finish_reason=None):
        self.delta = _FakeDelta(delta_text)
        self.finish_reason = finish_reason


class _FakeDelta:
    def __init__(self, content=""):
        self.content = content


def test_llm_init():
    llm = LLM()
    assert llm.model is not None
    assert isinstance(llm.model, str) and len(llm.model) > 0
    assert llm.client is not None
    # Client should be an OpenAI-compatible client
    assert hasattr(llm.client, "chat") or hasattr(llm.client, "base_url")


async def test_stream_chat_simple():
    llm = LLM()

    async def fake_create(kwargs):
        class FakeStream:
            async def __aiter__(self):
                yield _FakeChunk(delta_text="hello")
        return FakeStream()

    llm._create_stream = fake_create
    chunks = []

    async for chunk in llm.stream_chat([{"role": "user", "content": "hi"}]):
        chunks.append(chunk)

    assert len(chunks) == 1
    assert chunks[0].choices[0].delta.content == "hello"


async def test_stream_chat_with_tools():
    llm = LLM()
    sent_kwargs = {}

    async def fake_create(kwargs):
        sent_kwargs.update(kwargs)
        class FakeStream:
            async def __aiter__(self):
                yield _FakeChunk(delta_text="ok")
        return FakeStream()

    llm._create_stream = fake_create
    tools = [{"function": {"name": "test_tool"}}]

    chunks = []
    async for chunk in llm.stream_chat([{"role": "user", "content": "hi"}], tools=tools):
        chunks.append(chunk)

    assert sent_kwargs.get("tools") == tools
    assert sent_kwargs.get("tool_choice") == "auto"
    assert len(chunks) == 1


async def test_stream_chat_tool_choice_none():
    llm = LLM()
    sent_kwargs = {}

    async def fake_create(kwargs):
        sent_kwargs.update(kwargs)
        class FakeStream:
            async def __aiter__(self):
                yield _FakeChunk(delta_text="summary")
        return FakeStream()

    llm._create_stream = fake_create

    chunks = []
    async for chunk in llm.stream_chat([{"role": "user", "content": "summarize"}], tools=[], tool_choice="none"):
        chunks.append(chunk)

    assert "tools" not in sent_kwargs
    assert "tool_choice" not in sent_kwargs
    assert len(chunks) == 1
    assert chunks[0].choices[0].delta.content == "summary"


async def test_create_stream_passthrough():
    """_create_stream receives the full kwargs dict built by stream_chat."""
    llm = LLM()
    captured = {}

    async def tracker(kwargs):
        captured.update(kwargs)
        class FakeStream:
            async def __aiter__(self):
                yield _FakeChunk(delta_text="ok")
        return FakeStream()

    llm._create_stream = tracker
    chunks = []
    async for chunk in llm.stream_chat([{"role": "user", "content": "hi"}]):
        chunks.append(chunk)

    assert len(chunks) == 1
    # stream_chat builds a kwargs dict with the expected keys
    assert "model" in captured
    assert "messages" in captured
    assert captured["messages"] == [{"role": "user", "content": "hi"}]
    assert captured.get("stream") is True
    assert "stream_options" in captured


async def _capture_kwargs(llm):
    captured = {}

    async def tracker(kwargs):
        captured.update(kwargs)
        class FakeStream:
            async def __aiter__(self):
                yield _FakeChunk(delta_text="ok")
        return FakeStream()

    llm._create_stream = tracker

    async for _ in llm.stream_chat([{"role": "user", "content": "hi"}]):
        pass

    return captured


async def test_stream_chat_thinking_enabled_sends_reasoning_only():
    llm = LLM()
    assert llm.settings.LLM_THINKING_ENABLED is True
    captured = await _capture_kwargs(llm)
    # Thinking mode: reasoning via extra_body, and temperature/top_p omitted
    # (DeepSeek V4 ignores them while thinking).
    assert captured["extra_body"]["thinking"] == {"type": "enabled"}
    assert captured["extra_body"]["reasoning_effort"] == llm.settings.LLM_REASONING_EFFORT
    assert "temperature" not in captured
    assert "top_p" not in captured
    assert captured["max_tokens"] == llm.settings.LLM_MAX_TOKENS


async def test_stream_chat_thinking_disabled_sends_temperature():
    llm = LLM()
    llm.settings = llm.settings.model_copy(update={"LLM_THINKING_ENABLED": False})
    captured = await _capture_kwargs(llm)
    assert "extra_body" not in captured
    assert captured["temperature"] == llm.settings.LLM_TEMPERATURE
    assert captured["top_p"] == llm.settings.LLM_TOP_P


async def _capture_kwargs_with_session(llm, **extra):
    captured = {}

    async def tracker(kwargs):
        captured.update(kwargs)
        class FakeStream:
            async def __aiter__(self):
                yield _FakeChunk(delta_text="ok")
        return FakeStream()

    llm._create_stream = tracker
    async for _ in llm.stream_chat([{"role": "user", "content": "hi"}], **extra):
        pass
    return captured


async def test_stream_chat_session_id_thinking_enabled():
    llm = LLM()
    assert llm.settings.LLM_THINKING_ENABLED is True
    captured = await _capture_kwargs_with_session(llm, session_id="session-123")
    assert captured["extra_body"]["session_id"] == "session-123"
    assert captured["extra_body"]["thinking"] == {"type": "enabled"}
    assert "temperature" not in captured
    assert "top_p" not in captured


async def test_stream_chat_session_id_thinking_disabled_keeps_temperature():
    llm = LLM()
    llm.settings = llm.settings.model_copy(update={"LLM_THINKING_ENABLED": False})
    captured = await _capture_kwargs_with_session(llm, session_id="session-123")
    assert captured["extra_body"] == {"session_id": "session-123"}
    assert captured["temperature"] == llm.settings.LLM_TEMPERATURE
    assert captured["top_p"] == llm.settings.LLM_TOP_P
