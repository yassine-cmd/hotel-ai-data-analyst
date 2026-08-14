"""LLM API client wrapping OpenRouter/OpenAI-compatible chat completions with
retry logic and streaming support."""

from typing import List, Dict, Any, Optional, AsyncGenerator
import logging
from openai import AsyncOpenAI, APIConnectionError, APITimeoutError, RateLimitError, InternalServerError
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type
from config import get_settings

logger = logging.getLogger(__name__)

_RETRYABLE_OPENAI = (APIConnectionError, APITimeoutError, RateLimitError, InternalServerError)


class LLM:
    def __init__(self):
        self.settings = get_settings()
        self.client = AsyncOpenAI(api_key=self.settings.LLM_API_KEY, base_url=self.settings.LLM_BASE_URL, timeout=self.settings.LLM_TIMEOUT_SECONDS)
        self.model = self.settings.LLM_MODEL
        logger.info("LLM initialized | model=%s", self.model)

    @retry(
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=0.5, min=0.5, max=4),
        retry=retry_if_exception_type(_RETRYABLE_OPENAI),
    )
    async def _create_stream(self, kwargs: Dict[str, Any]) -> Any:
        return await self.client.chat.completions.create(**kwargs)

    async def stream_chat(self, messages: List[Dict[str, Any]], tools: Optional[List[Dict[str, Any]]] = None, tool_choice: str = "auto", stop: Optional[List[str]] = None, session_id: Optional[str] = None) -> AsyncGenerator[Any, None]:
        kwargs = {
            "model": self.model, "messages": messages,
            "max_tokens": self.settings.LLM_MAX_TOKENS,
            "stream": True, "stream_options": {"include_usage": True},
        }
        # Non-standard OpenAI params ride in extra_body. OpenRouter's provider
        # sticky routing keys on session_id, pinning every request of a
        # conversation to the same upstream so the prompt-cache stays warm
        # from the first request (without it, stickiness only engages after a
        # cache hit is observed and the conversation key is derived from
        # message hashing, which drifts when the schema reference rebuilds).
        extra_body: Dict[str, Any] = {}
        if session_id:
            extra_body["session_id"] = session_id
        # Thinking models (DeepSeek V4 family) expose reasoning as a request
        # parameter, not a separate model. Reasoning effort is sent only when
        # thinking is enabled, via extra_body (non-standard OpenAI params).
        if self.settings.LLM_THINKING_ENABLED:
            extra_body["thinking"] = {"type": "enabled"}
            extra_body["reasoning_effort"] = self.settings.LLM_REASONING_EFFORT
        else:
            # temperature/top_p are ignored by DeepSeek while thinking mode is
            # on, so they are only sent when it is off — where they actually
            # take effect (and stay available for non-thinking models).
            if self.settings.LLM_TEMPERATURE is not None:
                kwargs["temperature"] = self.settings.LLM_TEMPERATURE
            if self.settings.LLM_TOP_P is not None:
                kwargs["top_p"] = self.settings.LLM_TOP_P
        if extra_body:
            kwargs["extra_body"] = extra_body
        # Deliberately falsy-checked: an explicit empty list means "attach no
        # tool schemas and no tool_choice at all" for this call — not just
        # "tools happen to be empty". Passing tool_choice without any tools
        # defined is invalid/undefined on several OpenAI-compatible backends,
        # and attaching schemas just to immediately forbid them via
        # tool_choice="none" re-primes the model with "here are your
        # functions" right when the goal is the opposite. See core.py's
        # forced-summary path for why this matters.
        if tools:
            kwargs["tools"] = tools
            kwargs["tool_choice"] = tool_choice
        # Hard generation-level circuit breaker: unlike tool_choice (whose
        # enforcement is provider/model-specific and unverifiable from here),
        # `stop` is a base sampling parameter honored by virtually every
        # OpenAI-compatible backend. The instant the growing completion
        # matches one of these strings, the backend stops sampling — the
        # corrupted continuation is never generated, not just filtered
        # after the fact.
        if stop:
            kwargs["stop"] = stop
        logger.info("LLM stream | model=%s | messages=%d | tools=%d | stop=%s", self.model, len(messages), len(tools) if tools else 0, bool(stop))
        stream = await self._create_stream(kwargs)
        async for chunk in stream:
            yield chunk
