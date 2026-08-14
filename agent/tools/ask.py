"""Question tool: ask the user multi-choice or open-ended clarifying questions."""

from typing import Any, Dict, List

from .base import BaseTool, StandardToolResult, build_envelope


class QuestionTool(BaseTool):
    name = "question"
    description = (
        "Ask the user one or more clarifying questions when their request is too broad, vague, "
        "ambiguous, or missing critical scope (e.g. time range, specific metric/KPI, entity, or "
        "comparison baseline). Use this BEFORE any analysis — do NOT guess a scope or run "
        "execute_sql/run_python/describe_table first. Each question may include 2-4 suggested "
        "'options' and a 'multi' flag when several answers can apply at once. Only ask when the "
        "request is genuinely unanswerable as stated; a clear, specific question should go "
        "straight to planning and execution."
    )
    input_schema = {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "description": "REQUIRED. One sentence describing what this call does.",
            },
            "questions": {
                "type": "array",
                "description": "One or more questions. Usually 1; ask several only when independent clarifications are needed together.",
                "items": {
                    "type": "object",
                    "properties": {
                        "question": {
                            "type": "string",
                            "description": "The question text, concise and specific.",
                        },
                        "options": {
                            "type": "array",
                            "items": {"type": "string"},
                            "description": "Optional 2-4 suggested answers the user can pick from. Omit for a free-form question.",
                        },
                        "multi": {
                            "type": "boolean",
                            "description": "If true the user may select multiple options; otherwise single choice. Default false.",
                        },
                    },
                    "required": ["question"],
                },
            }
        },
        "required": ["questions"],
    }

    @staticmethod
    def _parse_questions(inputs: Dict[str, Any]) -> List[Dict[str, Any]]:
        raw = inputs.get("questions", [])
        if isinstance(raw, dict):
            raw = [raw]
        if not isinstance(raw, list):
            return []
        out: List[Dict[str, Any]] = []
        for q in raw:
            if not isinstance(q, dict):
                continue
            text = (q.get("question") or "").strip()
            if not text:
                continue
            opts = q.get("options") or []
            if not isinstance(opts, list):
                opts = []
            opts = [str(o).strip() for o in opts if str(o).strip()]
            multi = bool(q.get("multi", False))
            out.append({"question": text, "options": opts, "multi": multi})
        return out

    async def run(self, inputs: Dict[str, Any], context: Dict[str, Any]) -> Any:
        questions = self._parse_questions(inputs)
        return StandardToolResult(
            status="success",
            tool="question",
            summary=f"Asked {len(questions)} question(s)",
            data=build_envelope("success", error=None, error_kind=None, asked=len(questions), questions=questions),
            ui_data={"asked": len(questions)},
        )
