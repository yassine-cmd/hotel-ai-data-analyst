"""Step loop: the step-by-step reasoning + tool execution engine.

Extracted from core.py to keep the orchestration (session lifecycle, persist)
separate from the per-step loop logic.
"""

import asyncio
import json
import logging
import re
import secrets
from typing import List, Dict, Any, Optional, Set, Tuple, AsyncGenerator

from .context import Context
from .errors import classify_provider_error
from .events import (
    phase, thinking as ev_thinking, text as ev_text, text_commit,
    error as ev_error, question as ev_question, tool_start, tool_end,
    answer_structured as ev_answer, stream_completed,
)
from .tools import StandardToolResult

logger = logging.getLogger(__name__)

# Token budgeting follows the N+1 model: no heuristic estimates. The first
# request of a turn goes out whole; every later budget decision is derived
# from the API's exact prompt token count of the previous request (see
# TokenCalibrator in context.py).

# Hard stop sequences for the forced-summary call (see _execute_turn's MAX_STEPS
# branch): the openers of every common tool-call sentinel scheme. None of
# these ever legitimately opens a sentence in analysis prose, so there's no
# false-positive risk — the backend halts sampling the instant one appears,
# which means the corrupted continuation past it is never generated at all.
_TOOL_CALL_STOP_SEQUENCES = ["<｜", "<|", "<invoke", "<function_calls"]

# Backstop for forced-summary replies, in case a `stop` match lands mid-token
# (some backends match stop strings post-detokenization, one token late) and
# a small fragment gets through anyway before the connection is cut.
#
# Deliberately NOT a keyword match on specific tag names (tool_call, invoke,
# parameter, ...) — a new model/provider would just need a different tag name
# to slip past that. Instead this denies the *character classes* every one
# of these sentinel schemes is built from, which is what makes the gate
# exhaustive rather than a list of known cases:
#   - U+FF5C (｜ fullwidth vertical line) / U+2581 (▁ low line) — DeepSeek/
#     Kimi-style special-token spelling (e.g. <｜tool▁calls▁begin｜>)
#   - literal "<|" — ChatML/Llama/Qwen-style sentinel opener (<|tool_call|>,
#     <|im_start|>, etc.)
#   - "<" or "<invoke"/"<parameter" tags — Claude/Anthropic-style
#     XML tool-call format
# None of these ever legitimately appear in analysis prose/Markdown, so
# false positives are effectively impossible while coverage stays open-ended
# for any future variant that still leans on one of these conventions.
_TOOL_SENTINEL_RE = re.compile(
    r'[\uFF5C\u2581]|<\|antml:|<\|?\s*/?\s*(?:antml:)?(?:invoke|parameter|tool_calls?)\b',
    re.IGNORECASE,
)


def _build_sources(turn_tool_calls: List[Dict]) -> List[Dict]:
    sources: List[Dict] = []
    for tc in turn_tool_calls:
        if len(sources) >= 20:
            break
        tool_name = tc.get("tool", "")
        status = tc.get("status", "")
        args = tc.get("args", {})
        result = tc.get("result", {})

        if tool_name == "execute_sql" and status in ("success", "partial"):
            queries = args.get("queries", [])
            for i, r in enumerate(result.get("results", [])):
                if len(sources) >= 20:
                    break
                if r.get("status") != "success":
                    continue
                sql = ""
                if i < len(queries):
                    sql = queries[i].get("sql", "")
                row_count = r.get("true_row_count")
                tables = r.get("tables", [])
                if row_count is not None:
                    table_str = ", ".join(tables) if tables else "data"
                    desc = f"Queried {table_str} ({row_count:,} rows)"
                else:
                    desc = "Ran a SQL query"
                sources.append({
                    "type": "sql",
                    "label": "SQL query",
                    "description": desc,
                    "sql": sql[:200] if sql else "",
                    "row_count": row_count,
                    "tables": tables,
                })

        elif tool_name == "describe_table" and status == "success":
            for table_name in result.get("described", []):
                if len(sources) >= 20:
                    break
                sources.append({
                    "type": "table",
                    "label": "Explored table",
                    "description": f"Explored {table_name} structure",
                    "table_name": table_name,
                })

        elif tool_name == "create_chart_spec" and status == "success":
            chart_spec = result.get("chart_spec", {})
            chart_type = chart_spec.get("chart_type", "")
            title = chart_spec.get("title", "")
            desc = f"Generated {chart_type} chart" if chart_type else "Generated chart"
            if title:
                desc += f" — {title}"
            sources.append({
                "type": "chart",
                "label": "Generated chart",
                "description": desc,
                "chart_type": chart_type,
                "title": title,
            })

    return sources


def _strip_tool_sentinels(text: str) -> str:
    if not text:
        return text
    match = _TOOL_SENTINEL_RE.search(text)
    if not match:
        return text
    logger.warning(
        "Hallucinated tool-call syntax leaked into forced-summary reply | pos=%d | preview=%.200s",
        match.start(), text[match.start():match.start() + 200],
    )
    salvage = text[:match.start()].rstrip()
    return salvage if len(salvage) > 40 else ""


def _collect_questions(tool_calls: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    questions: List[Dict[str, Any]] = []
    for tc in tool_calls:
        if tc["function"]["name"] != "question":
            continue
        raw_args = tc["function"]["arguments"]
        try:
            args = json.loads(raw_args) if isinstance(raw_args, str) else raw_args
        except Exception:
            args = {}
        from .tools import QuestionTool
        questions.extend(QuestionTool._parse_questions(args or {}))
    return questions


def _format_questions_as_text(questions: List[Dict[str, Any]]) -> str:
    if not questions:
        return ""
    parts = []
    for i, q in enumerate(questions, 1):
        head = f"Question {i}: {q['question']}" if len(questions) > 1 else f"Question: {q['question']}"
        if q.get("options"):
            kind = " (choose all that apply)" if q.get("multi") else ""
            parts.append(f"{head}\nOptions{kind}: {'; '.join(q['options'])}")
        else:
            parts.append(head)
    return "\n\n".join(parts)


async def _dispatch(self, tool_call: Dict, tool_args: Dict[str, Any], context: Dict) -> Tuple[str, StandardToolResult]:
    """Agent._dispatch — assign onto Agent in core.py."""
    tool_name = tool_call["function"]["name"]
    logger.info("Executing tool: %s | args=%s", tool_name, json.dumps(tool_args))
    tool = self._tools.get(tool_name)
    if not tool:
        return tool_name, self._error(f"Unknown tool: {tool_name}", context)
    try:
        result = await asyncio.wait_for(tool.run(tool_args, context), timeout=self._tool_timeout)
    except asyncio.TimeoutError:
        logger.warning("Tool %s timed out after %ds", tool_name, self._tool_timeout)
        return tool_name, self._error(f"Tool execution timed out after {self._tool_timeout}s.", context)
    except Exception as e:
        logger.exception("Tool %s raised unexpectedly", tool_name)
        return tool_name, self._error(f"Tool execution error: {str(e)}", context)
    if result.repair_hint:
        logger.info("Tool %s repair_hint: %s", tool_name, result.repair_hint)
    return tool_name, result


async def _stream_llm(self, messages: List[Dict[str, Any]], tracker, tool_choice: str = "auto",
                      tools: Optional[List[Dict[str, Any]]] = None,
                      stop: Optional[List[str]] = None,
                      session_id: Optional[str] = None) -> AsyncGenerator[Dict[str, Any], None]:
    """Agent._stream_llm — assign onto Agent in core.py."""
    if tools is None:
        tools = self._tool_schemas
    content_buffer = ""
    thinking_buffer = ""
    tool_calls_dict = {}
    last_usage = None
    try:
        async for chunk in self.llm.stream_chat(messages, tools, tool_choice=tool_choice, stop=stop, session_id=session_id):
            if hasattr(chunk, "usage") and chunk.usage:
                last_usage = chunk.usage
            if not chunk.choices:
                continue
            choice = chunk.choices[0]
            delta = choice.delta
            reasoning = getattr(delta, "reasoning_content", None) or getattr(delta, "reasoning", None)
            if reasoning:
                thinking_buffer += reasoning
                yield ev_thinking(delta=reasoning)
            if delta.content:
                content_buffer += delta.content
                yield ev_text(delta=delta.content)
            if delta.tool_calls:
                for tc_delta in delta.tool_calls:
                    idx = tc_delta.index
                    if idx not in tool_calls_dict:
                        tool_calls_dict[idx] = {"id": "", "type": "function", "function": {"name": "", "arguments": ""}}
                    if tc_delta.id is not None:
                        tool_calls_dict[idx]["id"] += tc_delta.id
                    if tc_delta.function is not None:
                        if tc_delta.function.name is not None:
                            tool_calls_dict[idx]["function"]["name"] += tc_delta.function.name
                        if tc_delta.function.arguments is not None:
                            tool_calls_dict[idx]["function"]["arguments"] += tc_delta.function.arguments
    except Exception as e:
        logger.exception("LLM stream failed")
        payload = classify_provider_error(e)
        yield ev_error(
            message=payload["message"],
            code=payload["code"],
            retryable=payload["retryable"],
            detail=payload["detail"],
        )
        return
    # Record usage exactly once per request, from the final chunk. Streaming
    # with include_usage repeats the cumulative full-request totals on every
    # chunk; recording per-chunk would inflate session totals by ~chunk count.
    if last_usage is not None:
        tracker.record_step(last_usage)
    yield stream_completed(content=content_buffer, thinking=thinking_buffer, tool_calls=list(tool_calls_dict.values()))


async def _execute_turn(self, ctx, tracker, session_budget, context,
                        session_dataframes, guard, schema_dict,
                        live_budget, _result):
    """Step-by-step reasoning + tool execution loop.

    Yields events (phase, text, thinking, tool_start, tool_end, question,
    text_commit, answer_structured).
    Populates the _result dict with final turn state.
    """
    from config import get_settings

    session_id = context.get("session_id")
    steps_run = 0
    turn_tool_calls: List[Dict] = []
    thinking_per_step: Dict[int, str] = {}
    content_buffer = ""
    summary_text = ""
    answer_structured: Optional[Dict[str, Any]] = None
    ask_only_turn = False
    asked_questions = None

    # Seed the semi-stable tiers once at turn start (unless a resumed
    # session already carries them). Thereafter each is only rebuilt
    # when its own trigger fires — see the end of each step below.
    if ctx.reference_data_msg is None:
        ctx.upsert_reference(self._build_reference(ctx.tables_touched, guard=guard, schema_dict=schema_dict, unauthorized=context.get("unauthorized_tables", [])))

    for step in range(self.max_steps):
        steps_run = step + 1
        logger.info("--- Step %d/%d | messages=%d | cumulative_prompt=%d ---", steps_run, self.max_steps, len(ctx.messages), tracker.total_prompt)

        prev_refs = set(ctx.tables_touched)
        prev_dfs = set(session_dataframes.keys())

        step_context = ctx._build_step_context(steps_run, self.max_steps,
                                               available_dataframes=session_dataframes)
        progress_notes = self._convergence_notes(ctx, steps_run)

        content_buffer = ""
        thinking_buffer = ""
        tool_calls = []
        stream_interrupted = False

        live_messages = ctx.build_request(max_tokens=live_budget, step_context=step_context, progress_notes=progress_notes)
        logger.info("CTX LIVE step=%d | %s", steps_run, ctx.log_summary(live_messages=live_messages))

        diag_settings = get_settings()
        if diag_settings.ENABLE_PROMPT_DIAGNOSTICS:
            self._log_prompt_diagnostics(
                logger, live_messages, self._prev_diag_messages, steps_run,
                ctx.calibrator,
            )
            self._prev_diag_messages = live_messages

        yield phase("reasoning", step=steps_run)
        async for event in self._stream_llm(live_messages, tracker, session_id=session_id):
            if event["type"] == "stream_completed":
                content_buffer = event["content"]
                thinking_buffer = event["thinking"]
                if thinking_buffer:
                    thinking_per_step[steps_run] = thinking_buffer
                tool_calls = event["tool_calls"]
            elif event["type"] == "error":
                yield event
                stream_interrupted = True
            elif event["type"] == "thinking":
                yield event
            elif event["type"] == "text":
                yield ev_text(delta=event["delta"], tentative=True)

        if stream_interrupted:
            yield ev_text(delta="I lost connection to the model mid-step. Please resend your question or continue with a follow-up.", tentative=False)
            break

        tracker.log_step(step)
        ctx.record_real_usage(tracker.step_prompt, live_messages)
        logger.info("CTR RESPONSE | step=%d | sent=%d | recv=%d | think=%d | content=%dch | calls=%d",
                    step, tracker.step_prompt, tracker.step_completion, tracker.step_thinking,
                    len(content_buffer or ""), len(tool_calls))
        if thinking_buffer:
            logger.info("THINKING:\n%s", thinking_buffer)
        if content_buffer:
            logger.info("CONTENT:\n%s", content_buffer)

        raw_content = content_buffer or ""

        question_calls = [tc for tc in tool_calls if tc.get("function", {}).get("name") == "question"]
        if question_calls:
            questions = _collect_questions(question_calls)
            content_buffer = _format_questions_as_text(questions)
            asked_questions = questions
            ctx.append_assistant(content_buffer, [])
            yield ev_question(questions=questions)
            logger.info("Question(s) asked (%d). Ending loop.", len(questions))
            ask_only_turn = True
            break

        if not tool_calls:
            content_buffer = raw_content
            answer_structured = {"answer": content_buffer}
            sources = _build_sources(turn_tool_calls)
            if sources:
                answer_structured["sources"] = sources
            ctx.append_assistant(content_buffer, tool_calls)
            yield phase("generating")
            yield text_commit()
            yield ev_answer(answer=answer_structured)
            logger.info("Natural completion. Ending loop.")
            logger.info("FINAL ANSWER:\n%s", content_buffer if content_buffer else "(empty)")
            break

        yield phase("executing_tool", step=steps_run)
        logger.info("%d tool call(s)", len(tool_calls))

        results_buffer: List[Tuple[str, Dict]] = []
        for tc in tool_calls:
            tool_func = tc.get("function", {})
            tool_name = tool_func.get("name")
            if not tool_name:
                raise ValueError(f"Malformed tool call: missing function name or id in {tc}")
            raw_args = tool_func.get("arguments", "{}")
            tc_id = tc.get("id", "")
            step_id = f"step_{steps_run}_{tool_name}_{tc_id[:8]}_{secrets.token_hex(4)}"

            tool_args = self._parse_tool_args(raw_args)

            if tool_args is None:
                llm_ctx = {"status": "error", "error_kind": "input", "message": f"Tool argument JSON syntax error in '{tool_name}': {raw_args[:500]}"}
                ui_ctx = {"error": llm_ctx["message"]}
                yield tool_start(tool=tool_name, step_id=step_id, args={})
                logger.info("Tool call: %s | invalid JSON args", tool_name)
                yield tool_end(tool=tool_name, result=ui_ctx, step_id=step_id)
                turn_tool_calls.append({"tool": tool_name, "args": {}, "description": None, "step_id": step_id, "status": "error", "result": {}})
                results_buffer.append((tc_id, llm_ctx))
                ctx.log_error(tool_name, llm_ctx["message"])
                continue

            action_text = tool_args.pop("action", None)

            yield tool_start(tool=tool_name, step_id=step_id, args=tool_args, description=action_text or "")
            if action_text:
                tool_args["action"] = action_text
            logger.info("Tool call: %s | action=%s", tool_name, action_text or "")

            context["_current_step_id"] = step_id
            _, result = await self._dispatch(tc, tool_args, context)
            llm_ctx = result.data
            ui_ctx = result.ui_data

            yield tool_end(tool=tool_name, result=ui_ctx, step_id=step_id)

            llm_status = llm_ctx.get("status", "unknown")

            turn_tool_calls.append({
                "tool": tool_name,
                "args": tool_args,
                "description": action_text,
                "step_id": step_id,
                "status": llm_status,
                "result": dict(ui_ctx),
            })

            logger.info("Tool result: %s | status=%s", tool_name, llm_status)

            if tool_name == "describe_table":
                tables = tool_args.get("tables", [])
                ctx.log_describe(tables)
            elif tool_name == "execute_sql":
                queries = tool_args.get("queries", [])
                tables: List[str] = []
                seen: Set[str] = set()
                for r in llm_ctx.get("results", []):
                    if r.get("status") == "success":
                        for t in r.get("tables", []):
                            if t not in seen:
                                seen.add(t)
                                tables.append(t)
                sql_error_msg = ""
                if llm_status in ("error", "partial"):
                    sql_error_msg = llm_ctx.get("error") or llm_ctx.get("message", "")
                ctx.log_sql(queries, tables, status=llm_status, error=sql_error_msg)
            elif tool_name == "run_python":
                code = tool_args.get("code", "")
                output_excerpt = (llm_ctx.get("output") or "")
                py_error = (llm_ctx.get("error") or "")
                ctx.log_python(code, output_excerpt, status=llm_status, error=py_error)

            if ctx.tables_touched:
                logger.info("Referenced tables: %s", sorted(ctx.tables_touched))

            results_buffer.append((tc["id"], llm_ctx))

        content_buffer = raw_content
        ctx.append_assistant(content_buffer, tool_calls)

        for tc_id, llm_ctx in results_buffer:
            ctx.append_tool_result(tc_id, llm_ctx)

        if set(ctx.tables_touched) != prev_refs:
            ctx.upsert_reference(self._build_reference(ctx.tables_touched, guard=guard, schema_dict=schema_dict, unauthorized=context.get("unauthorized_tables", [])))

    else:
        logger.warning("MAX_STEPS reached — summarizing without tools")
        yield phase("generating")
        ctx.append_system_note(
            "Step limit reached. No tools exist for this message — do not attempt to "
            "call one. Write your final answer now, as plain Markdown prose only "
            "(embed [CHART_x] tokens inline where relevant)."
        )
        summary_text = ""
        if session_dataframes:
            ctx.upsert_available_dataframes(session_dataframes)
        step_context = ctx._build_step_context(steps_run, self.max_steps)
        fallback_messages = ctx.build_request(max_tokens=live_budget, step_context=step_context)
        fallback_messages = Context.flatten_tool_rounds(fallback_messages)
        logger.info("CTX LIVE step=%d (max-steps summary) | %s", steps_run, ctx.log_summary(live_messages=fallback_messages))
        async for event in self._stream_llm(
            fallback_messages, tracker,
            tools=[],
            stop=_TOOL_CALL_STOP_SEQUENCES,
            session_id=session_id,
        ):
            if event["type"] in ("text", "thinking"):
                event["tentative"] = False
                yield event
            elif event["type"] == "stream_completed":
                summary_text = event["content"]
        summary_text = _strip_tool_sentinels(summary_text)
        ctx.record_real_usage(tracker.step_prompt, fallback_messages)
        if not summary_text:
            facts = ctx.verified_facts
            if facts:
                fact_lines = [f"{k} = {v}" for k, v in facts.items()]
                summary_text = (
                    "Limite d'étapes atteinte. Faits vérifiés avant l'interruption :\n"
                    + "\n".join(fact_lines)
                )
            else:
                summary_text = "Limite d'étapes atteinte — résultats partiels."
        answer_structured = {"answer": summary_text}
        sources = _build_sources(turn_tool_calls)
        if sources:
            answer_structured["sources"] = sources
        content_buffer = summary_text
        yield ev_answer(answer=answer_structured)
        ctx.append_assistant(summary_text, [])

    # Populate result dict for the caller
    _result["steps_run"] = steps_run
    _result["turn_tool_calls"] = turn_tool_calls
    _result["thinking_per_step"] = thinking_per_step
    _result["content_buffer"] = content_buffer
    _result["summary_text"] = summary_text
    _result["answer_structured"] = answer_structured
    _result["ask_only_turn"] = ask_only_turn
    _result["asked_questions"] = asked_questions
