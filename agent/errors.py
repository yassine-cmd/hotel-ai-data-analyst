"""Error classification and redaction for user-facing failure messages.

Every error that can surface in the chat UI is normalized to a structured
envelope: {code, message, retryable, detail}. ``message`` must be safe,
non-technical English a non-technical client can act on. ``detail`` carries
the technical cause and is intended for server logs / support only — never
for the chat UI.
"""

import re

from openai import (
    APIError,
    APIConnectionError,
    APITimeoutError,
    AuthenticationError,
    BadRequestError,
    InternalServerError,
    RateLimitError,
)

# Secret-looking tokens (API keys, bearer tokens) that must never reach logs
# or the client, no matter how they leak into an exception string.
_SECRET_PATTERN = re.compile(
    r"(sk-[A-Za-z0-9_\-]{8,}|Bearer\s+[A-Za-z0-9_\-\.]{16,}|api[_-]?key[\"']?\s*[:=]\s*[\"']?[A-Za-z0-9_\-]{16,})",
    re.IGNORECASE,
)


def redact(text: str) -> str:
    """Replace secret-looking substrings with a placeholder."""
    if not text:
        return ""
    return _SECRET_PATTERN.sub("[REDACTED]", text)


def classify_provider_error(e: BaseException) -> dict:
    """Map an LLM/provider exception to a safe {code, message, retryable, detail} envelope."""
    detail = redact(str(e))

    if isinstance(e, AuthenticationError):
        return {
            "code": "PROVIDER_AUTH",
            "message": "The AI service is not configured correctly. Please contact support.",
            "retryable": False,
            "detail": detail,
        }
    if isinstance(e, RateLimitError):
        return {
            "code": "PROVIDER_RATE_LIMIT",
            "message": "The AI service is busy right now. Please try again in a moment.",
            "retryable": True,
            "detail": detail,
        }
    if isinstance(e, APITimeoutError):
        return {
            "code": "PROVIDER_TIMEOUT",
            "message": "The AI service is taking too long to respond. Please try again.",
            "retryable": True,
            "detail": detail,
        }
    if isinstance(e, APIConnectionError):
        return {
            "code": "PROVIDER_ERROR",
            "message": "Could not reach the AI service. Please try again.",
            "retryable": True,
            "detail": detail,
        }
    if isinstance(e, InternalServerError):
        return {
            "code": "PROVIDER_ERROR",
            "message": "The AI service had a temporary problem. Please try again.",
            "retryable": True,
            "detail": detail,
        }
    if isinstance(e, BadRequestError):
        # Most common cause of a 400 from OpenAI-compatible APIs is the request
        # exceeding the model's context window.
        text = str(e).lower()
        if any(k in text for k in ("context length", "maximum context", "token", "length")):
            return {
                "code": "PROVIDER_CONTEXT",
                "message": "That question is too long for the AI service. Try a shorter question.",
                "retryable": False,
                "detail": detail,
            }
        return {
            "code": "PROVIDER_ERROR",
            "message": "The AI service rejected this request. Please try again or rephrase your question.",
            "retryable": False,
            "detail": detail,
        }
    if isinstance(e, APIError):
        return {
            "code": "PROVIDER_ERROR",
            "message": "The AI service had an unexpected problem. Please try again.",
            "retryable": True,
            "detail": detail,
        }
    # Anything else (assumed infra/agent-internal) — safe generic copy.
    return {
        "code": "PROVIDER_ERROR",
        "message": "The AI service had an unexpected problem. Please try again.",
        "retryable": True,
        "detail": detail,
    }


def stream_error(e: BaseException) -> dict:
    """Safe envelope for any non-provider stream failure (fallback)."""
    return {
        "code": "STREAM_ERROR",
        "message": "Something went wrong while processing your question. Please try again.",
        "retryable": True,
        "detail": redact(str(e)),
    }
