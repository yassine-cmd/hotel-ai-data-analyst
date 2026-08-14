"""Base tool abstractions: StandardToolResult, BaseTool ABC, and schema
construction helpers."""

from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import Any, Dict, List, Optional


@dataclass
class StandardToolResult:
    status: str
    tool: str
    summary: str
    data: Dict[str, Any]
    ui_data: Dict[str, Any]
    repair_hint: Optional[str] = None


def build_envelope(status: str, *, error: Optional[str] = None, error_kind: Optional[str] = None,
                   message: Optional[str] = None, hint: Optional[str] = None,
                   partial_reason: Optional[str] = None, **details: Any) -> Dict[str, Any]:
    env: Dict[str, Any] = {
        "status": status,
        "error": error,
        "error_kind": error_kind,
        "message": message,
        "hint": hint,
    }
    if partial_reason is not None:
        env["partial_reason"] = partial_reason
    env.update(details)
    return env


class BaseTool(ABC):
    name: str = ""
    description: str = ""
    input_schema: dict = {}

    @abstractmethod
    async def run(self, inputs: Dict[str, Any], context: Dict[str, Any]) -> Any:
        ...

    def _schema(self) -> Dict:
        return {"type": "function", "function": {"name": self.name, "description": self.description, "parameters": self.input_schema}}


def build_tool_schemas(tools: list) -> List[Dict]:
    return [t._schema() for t in tools]
