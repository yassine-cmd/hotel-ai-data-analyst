"""Python tool: execute user code in the sandbox with pre-injected DataFrames
and banned-package enforcement."""

import ast
import base64
import io
import logging
import re
from typing import Any, Dict, List, Optional

import pandas as pd

from ._helpers import _df_metadata
from .base import BaseTool, StandardToolResult, build_envelope
from config import PREINJECTED_PACKAGES

logger = logging.getLogger(__name__)

_DANGEROUS_MODULES = {"os", "subprocess", "sys", "ctypes", "socket", "shutil", "builtins", "importlib"}

_DANGEROUS_CALLS = {"__import__", "eval", "exec", "compile", "open", "input", "import_module"}


def _validate_python_code(code: str) -> None:
    try:
        tree = ast.parse(code)
    except SyntaxError as e:
        raise ValueError(f"Python syntax error: {e}") from e
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for alias in node.names:
                top = alias.name.split(".")[0]
                if top in _DANGEROUS_MODULES:
                    raise ValueError(f"Import of '{alias.name}' is not allowed in the sandbox")
        elif isinstance(node, ast.ImportFrom):
            src = (node.module or "").split(".")[0]
            if src in _DANGEROUS_MODULES:
                raise ValueError(f"Import from '{node.module}' is not allowed in the sandbox")
        elif isinstance(node, ast.Call):
            func = node.func
            name = func.id if isinstance(func, ast.Name) else (func.attr if isinstance(func, ast.Attribute) else "")
            if name in _DANGEROUS_CALLS:
                raise ValueError(f"Call to '{name}()' is not allowed in the sandbox")
    BLOCKED_KEYWORDS = [b"__import__", b"eval(", b"exec(", b"compile("]
    code_bytes = code.encode("utf-8")
    for kw in BLOCKED_KEYWORDS:
        if kw in code_bytes:
            raise ValueError(f"Code contains blocked pattern: {kw.decode()}")


class PythonTool(BaseTool):
    name = "run_python"
    _preinjected = ", ".join(PREINJECTED_PACKAGES)
    description = (
        "Run Python code in the sandbox. Inspect, transform, and summarize DataFrames. "
        "Call together with execute_sql in the same response — DataFrames from SQL are ready when Python runs. "
        "Variables persist across calls. Pre-injected: " + _preinjected + ". "
        "To chart data, use the create_chart_spec tool — do not generate images here."
    )
    input_schema = {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "description": "REQUIRED. One sentence describing what this call does.",
            },
            "code": {
                "type": "string",
                "description": "REQUIRED. Python code to execute in the sandbox. Has access to all session DataFrames by name.",
            },
        },
        "required": ["action", "code"],
    }

    async def run(self, inputs: Dict[str, Any], context: Dict[str, Any]) -> Any:
        code = inputs.get("code", "").strip() if isinstance(inputs.get("code"), str) else ""
        if not code:
            return StandardToolResult(
                status="infra_error",
                tool="run_python",
                summary="No code provided",
                data=build_envelope("error", error="No code provided. Pass Python code in the 'code' field.", error_kind="infra"),
                ui_data={},
            )

        try:
            _validate_python_code(code)
        except ValueError as e:
            return StandardToolResult(
                status="user_error",
                tool="run_python",
                summary=str(e),
                data=build_envelope("error", error=str(e), error_kind="input"),
                ui_data={},
                repair_hint="Fix the Python code syntax and retry.",
            )

        sandbox = context.get("sandbox")
        if not sandbox:
            return StandardToolResult(
                status="infra_error",
                tool="run_python",
                summary="Sandbox not initialized",
                data=build_envelope("error", error="Sandbox not initialized.", error_kind="infra"),
                ui_data={},
            )

        session_dataframes = context.get("session_dataframes", {})
        session_id = context["session_id"]
        client_id = context["client_id"]
        session_manager = context.get("session_manager")
        memory = context.get("memory")

        truncated_dfs = [n for n, m in session_dataframes.items() if isinstance(m, dict) and m.get("truncated")]
        truncated_warning = ""
        if len(truncated_dfs) == 1:
            truncated_warning = (
                f"Warning: DataFrame {truncated_dfs[0]} is TRUNCATED. "
                "Aggregates computed from it are partial unless re-derived from full SQL aggregation."
            )
        elif len(truncated_dfs) > 1:
            names = ", ".join(truncated_dfs)
            truncated_warning = (
                f"Warning: DataFrames {names} are TRUNCATED. "
                "Aggregates computed from them are partial unless re-derived from full SQL aggregation."
            )

        output = ""
        error: Optional[str] = None
        returned_dfs: List[str] = []

        try:
            existing_dfs: Dict[str, bytes] = {}
            if session_manager:
                all_names = await session_manager.store.list(client_id, session_id)
                all_dfs: Dict[str, pd.DataFrame] = {}
                for n in all_names:
                    df = await session_manager.store.get(client_id, session_id, n)
                    if df is not None:
                        all_dfs[n] = df
                referenced = {
                    name: df for name, df in all_dfs.items()
                    if re.search(r'\b' + re.escape(name) + r'\b', code)
                }
                selected = referenced if referenced else all_dfs
                for name, df in selected.items():
                    existing_dfs[name] = df.to_parquet(index=False)
            result = await sandbox.execute(code, session_id=session_id, dataframes=existing_dfs or None)
            if not isinstance(result, dict):
                raise ValueError("Sandbox returned a malformed payload.")
            returned_data = result.get("data") or {}
            had_error = bool(result.get("error"))
            preexisting = set(all_names) if session_manager else set()
            for name, parquet_b64 in returned_data.items():
                if not isinstance(parquet_b64, str):
                    continue
                if had_error and name in preexisting:
                    continue
                try:
                    parquet_bytes = base64.b64decode(parquet_b64)
                    df = pd.read_parquet(io.BytesIO(parquet_bytes))
                    session_dataframes[name] = _df_metadata(df)
                    if session_manager:
                        await session_manager.store.put(client_id, session_id, name, df)

                except Exception as e:
                    logger.warning("Failed to parse returned DataFrame '%s': %s", name, e)
            output = str(result.get("output") or "")
            error = result.get("error")
            returned_dfs = list(returned_data.keys())

            if output and memory is not None and not truncated_warning:
                for line in output.split("\n"):
                    stripped = line.strip()
                    if not stripped.startswith("FACT:"):
                        continue
                    body = stripped[len("FACT:"):].strip()
                    if "=" in body:
                        k, v = body.split("=", 1)
                        memory.verified_facts[k.strip()] = v.strip()
                    elif ":" in body:
                        k, v = body.split(":", 1)
                        memory.verified_facts[k.strip()] = v.strip()
                    elif body:
                        memory.verified_facts[f"fact_{len(memory.verified_facts) + 1}"] = body
        except Exception as e:
            error = str(e)

        llm_output = output
        if truncated_warning:
            llm_output = (truncated_warning + "\n\n" + llm_output) if llm_output else truncated_warning
        output_truncated = False
        if not error and len(output) > 20000:
            output_truncated = True
            error = (
                f"Python stdout output is too long ({len(output)} chars; max 20000). "
                f"The output was not returned. Print less — slice the data "
                f"(df.head(50)/df.tail(50)) or aggregate/summarize (.describe(), groupby) "
                f"before printing."
            )
            llm_output = ""

        hint: Optional[str] = None
        if error:
            err_text = str(error)
            first_line = err_text.split('\n')[0]
            hint = err_text if len(err_text) > len(first_line) else None

        status = "success"
        error_kind: Optional[str] = None
        partial_reason: Optional[str] = None
        summary: Optional[str] = None
        if error:
            error_kind = "sandbox"
            if llm_output.strip():
                status = "partial"
                partial_reason = "output_with_error"
                summary = f"Partial success. Error at: {str(error)[:200]}"
            else:
                status = "error"

        llm_ctx = build_envelope(
            status,
            error=error or None,
            error_kind=error_kind,
            message=summary,
            output=llm_output,
            returned_dfs=returned_dfs,
            output_truncated=output_truncated,
            partial_reason=partial_reason,
        )
        if error and hint:
            llm_ctx["hint"] = hint

        ui_ctx: Dict[str, Any] = {
            "output": output,
            "error": error,
            "status": status,
            "error_kind": error_kind,
            "output_truncated": output_truncated,
            "partial_reason": partial_reason,
        }
        if hint:
            ui_ctx["hint"] = hint

        std_status = "success" if status == "success" else "user_error"
        return StandardToolResult(
            status=std_status,
            tool="run_python",
            summary=(error or "")[:200] if error else f"Python code executed ({len(output)} chars output)",
            data=llm_ctx,
            ui_data=ui_ctx,
            repair_hint=hint[:200] if hint else None,
        )
