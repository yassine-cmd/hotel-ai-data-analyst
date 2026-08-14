"""Describe tool: return DDL schema for named tables from the pre-populated
schema dictionary (no SQL dependency)."""

import ast
import json
from typing import Any, Dict, List

from .base import BaseTool, StandardToolResult, build_envelope
from ..db import compile_ddl


class DescribeTool(BaseTool):
    name = "describe_table"
    description = "Full schema (columns, types, PKs, FKs, descriptions) for one or more tables. Accepts a list of table names."
    input_schema = {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "description": "REQUIRED. One sentence describing what this call does.",
            },
            "tables": {
                "type": "array",
                "items": {"type": "string"},
                "description": "List of table names to describe, e.g. ['client', 'reservation']",
            },
        },
        "required": ["tables"],
    }

    @staticmethod
    def _parse_tables(inputs: Dict[str, Any]) -> List[str]:
        raw = inputs.get("tables", [])
        if isinstance(raw, str):
            raw = [t.strip() for t in raw.split(",") if t.strip()]
            if len(raw) == 1 and raw[0].strip().startswith("["):
                try:
                    raw = json.loads(raw[0])
                except Exception:
                    try:
                        raw = ast.literal_eval(raw[0])
                    except Exception:
                        pass
        if not isinstance(raw, list):
            return []
        return [t.strip() for t in raw if isinstance(t, str) and t.strip()]

    async def run(self, inputs: Dict[str, Any], context: Dict[str, Any]) -> Any:
        guard = context.get("guard")
        schema_dict = context.get("schema_dict")
        if not schema_dict:
            return StandardToolResult(
                status="infra_error",
                tool="describe_table",
                summary="Schema dictionary not available",
                data=build_envelope("error", error="Schema dictionary not available.", error_kind="infra"),
                ui_data={},
            )
        raw_tables = self._parse_tables(inputs)
        if not raw_tables:
            return StandardToolResult(
                status="user_error",
                tool="describe_table",
                summary="No tables provided",
                data=build_envelope("error", error="Provide at least one table name via the 'tables' parameter.", error_kind="input"),
                ui_data={},
                repair_hint="Pass table names in the 'tables' array, e.g. ['clients', 'bookings']",
            )
        schema_lower_map = {k.lower(): k for k in schema_dict.keys()}
        unauthorized_lower = {str(t).lower() for t in (context.get("unauthorized_tables") or [])}
        missing = []
        subset = {}
        blocked = {}
        accessible_list = {}
        for t in raw_tables:
            t_clean = t.strip()
            t_lower = t_clean.lower()
            if t_lower not in schema_lower_map:
                missing.append(t_clean)
                continue
            actual_name = schema_lower_map[t_lower]
            is_sensitive_blocked = guard is not None and guard.is_sensitive_table(actual_name)
            is_permission_blocked = actual_name.lower() in unauthorized_lower
            if is_sensitive_blocked or is_permission_blocked:
                is_sensitive = bool(schema_dict.get(actual_name, {}).get("is_sensitive"))
                blocked[actual_name] = {
                    "queryable": False,
                    "accessible_columns": [],
                    "reason": (
                        "Table holds regulated personal data — not queryable by any user"
                        if is_sensitive
                        else "Table is not granted to this user's permissions"
                    ),
                }
            else:
                subset[actual_name] = schema_dict[actual_name]
                if guard is not None:
                    cols = guard.accessible_columns(actual_name)
                    if cols:
                        accessible_list[actual_name] = cols
        ddl = compile_ddl(subset) if subset else ""
        result: Dict[str, Any] = {"described": list(subset.keys())}
        if ddl:
            result["ddl"] = ddl
        if blocked:
            result["blocked_tables"] = blocked
        if accessible_list:
            result["accessible_columns"] = accessible_list
        if missing:
            result["not_found"] = missing
        has_any = bool(subset) or bool(blocked)
        llm_ctx = build_envelope("success" if has_any else "error", error=None if has_any else f"None of the requested tables exist: {raw_tables}", error_kind=None, **result)
        summary_parts = []
        if subset:
            summary_parts.append(f"described {len(subset)} table(s)")
        if blocked:
            summary_parts.append(f"{len(blocked)} blocked")
        if missing:
            summary_parts.append(f"{len(missing)} not found")
        return StandardToolResult(
            status="success" if has_any else "user_error",
            tool="describe_table",
            summary="; ".join(summary_parts) if summary_parts else "No tables found",
            data=llm_ctx,
            ui_data={"output": ddl, "described": list(subset.keys()), "blocked": list(blocked.keys()), "not_found": missing},
            repair_hint=f"None of the requested tables exist: {raw_tables}" if not has_any else None,
        )
