"""Stream event types, transport abstraction, and SSE serialization.

Every event yielded by Agent.analyze() is a dict with a "type" key. This
module defines the TypedDict contract for each event type, a TransportAdapter
ABC for pluggable serialization, and the built-in SSETransport.

13 event types:
  phase, status, summary, thinking, text, text_commit, error,
  question, tool_start, tool_end, answer_structured, stream_completed, done
"""

import json
from abc import ABC, abstractmethod
from typing import List, Literal, Optional, TypedDict, Union


PhaseKind = Literal["summarizing", "context_built", "reasoning", "executing_tool", "generating"]


class PhaseEvent(TypedDict, total=False):
    type: Literal["phase"]
    phase: PhaseKind
    step: int


class StatusEvent(TypedDict, total=False):
    type: Literal["status"]
    phase: Literal["context_built"]
    max_steps: int


class SummaryEvent(TypedDict, total=False):
    type: Literal["summary"]
    content: str
    structured_state: dict


class ThinkingEvent(TypedDict, total=False):
    type: Literal["thinking"]
    delta: str


class TextEvent(TypedDict, total=False):
    type: Literal["text"]
    delta: str
    tentative: bool


class TextCommitEvent(TypedDict, total=False):
    type: Literal["text_commit"]


class ErrorEvent(TypedDict, total=False):
    type: Literal["error"]
    message: str
    code: str
    retryable: bool
    detail: str


class QuestionEvent(TypedDict, total=False):
    type: Literal["question"]
    questions: list


class ToolStartEvent(TypedDict, total=False):
    type: Literal["tool_start"]
    tool: str
    step_id: str
    args: dict
    description: str


class ToolEndEvent(TypedDict, total=False):
    type: Literal["tool_end"]
    tool: str
    result: dict
    step_id: str


class AnswerStructuredEvent(TypedDict, total=False):
    type: Literal["answer_structured"]
    answer: dict


class StreamCompletedEvent(TypedDict, total=False):
    type: Literal["stream_completed"]
    content: str
    thinking: str
    tool_calls: list


class DoneEvent(TypedDict, total=False):
    type: Literal["done"]
    meta: dict


StreamEvent = Union[
    PhaseEvent, StatusEvent, SummaryEvent, ThinkingEvent, TextEvent,
    TextCommitEvent, ErrorEvent, QuestionEvent, ToolStartEvent, ToolEndEvent,
    AnswerStructuredEvent, StreamCompletedEvent, DoneEvent,
]


def phase(phase: PhaseKind, step: Optional[int] = None) -> PhaseEvent:
    r: PhaseEvent = {"type": "phase", "phase": phase}
    if step is not None:
        r["step"] = step
    return r


def status(max_steps: int) -> StatusEvent:
    return {"type": "status", "phase": "context_built", "max_steps": max_steps}


def summary(content: str, structured_state: dict) -> SummaryEvent:
    return {"type": "summary", "content": content, "structured_state": structured_state}


def thinking(delta: str) -> ThinkingEvent:
    return {"type": "thinking", "delta": delta}


def text(delta: str, tentative: bool = False) -> TextEvent:
    return {"type": "text", "delta": delta, "tentative": tentative}


def text_commit() -> TextCommitEvent:
    return {"type": "text_commit"}


def error(message: str, code: Optional[str] = None,
          retryable: Optional[bool] = None,
          detail: Optional[str] = None) -> ErrorEvent:
    r: ErrorEvent = {"type": "error", "message": message}
    if code is not None:
        r["code"] = code
    if retryable is not None:
        r["retryable"] = retryable
    if detail is not None:
        r["detail"] = detail
    return r


def question(questions: list) -> QuestionEvent:
    return {"type": "question", "questions": questions}


def tool_start(tool: str, step_id: str, args: dict, description: str = "") -> ToolStartEvent:
    r: ToolStartEvent = {"type": "tool_start", "tool": tool, "step_id": step_id, "args": args}
    if description:
        r["description"] = description
    return r


def tool_end(tool: str, result: dict, step_id: str) -> ToolEndEvent:
    return {"type": "tool_end", "tool": tool, "result": result, "step_id": step_id}


def answer_structured(answer: dict) -> AnswerStructuredEvent:
    return {"type": "answer_structured", "answer": answer}


def stream_completed(content: str, thinking: str, tool_calls: list) -> StreamCompletedEvent:
    return {"type": "stream_completed", "content": content, "thinking": thinking, "tool_calls": tool_calls}


def done(meta: dict) -> DoneEvent:
    return {"type": "done", "meta": meta}


class TransportAdapter(ABC):
    @abstractmethod
    def encode(self, event: StreamEvent) -> str:
        """Serialize a StreamEvent to wire format."""

    def encode_many(self, events: List[StreamEvent]) -> List[str]:
        return [self.encode(e) for e in events]


class SSETransport(TransportAdapter):
    def encode(self, event: StreamEvent) -> str:
        event_type = event.pop("type", "message")
        return f"event: {event_type}\ndata: {json.dumps(event)}\n\n"
