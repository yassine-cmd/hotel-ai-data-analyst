"""Conversation state: message assembly, memory ledger, token estimation,
and the declarative ContextPipeline for LLM request construction."""

import copy
import json
import logging
from datetime import datetime
from typing import Any, Dict, List, Optional, Set, Union

from config import get_settings

logger = logging.getLogger(__name__)

MEMORY_OUTPUT_EXCERPT_CHARS = 200
MAX_CODE_LOG_ENTRIES = 40
MAX_REFERENCED_TABLES = 20


def _current_time_str() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M (local time)")


class Context:
    MESSAGE_KINDS = {
        "system", "user_query", "user_followup", "database_reference",
        "available_dataframes", "step_context", "summary",
        "assistant_final", "assistant_tool_round", "tool_result", "system_note",
    }

    def __init__(self, system_prompt: str, user_content: str):
        self.messages: List[Dict[str, Any]] = [
            {"role": "system", "content": system_prompt, "_kind": "system"},
            {"role": "user", "content": user_content, "_kind": "user_query"},
        ]
        self.reference_data_msg: Optional[Dict[str, Any]] = None
        self.available_dataframes_msg: Optional[Dict[str, Any]] = None
        self.calibrator = TokenCalibrator()

        self.tool_trail: List[str] = []
        self.tables_touched: List[str] = []
        self.recent_errors: List[str] = []
        self.verified_facts: Dict[str, Any] = {}
        self.user_intents: List[str] = []
        self.constraints: List[str] = []

    @property
    def memory(self):
        return self

    @staticmethod
    def _kind(msg: Dict) -> str:
        k = msg.get("_kind")
        return k if k else Context._fallback_kind(msg)

    # --- Append / upsert (message-building helpers) -----------------------

    def append_user_query(self, query: str) -> Dict:
        msg = {"role": "user", "content": query, "_kind": "user_followup"}
        self.messages.append(msg)
        return msg

    def append_assistant(self, content: str, tool_calls: List[Dict]) -> Dict:
        kind = "assistant_tool_round" if tool_calls else "assistant_final"
        msg: Dict = {"role": "assistant", "_kind": kind}
        if tool_calls:
            msg["content"] = content or None
            msg["tool_calls"] = tool_calls
        else:
            msg["content"] = content
        self.messages.append(msg)
        return msg

    def append_tool_result(self, tool_call_id: str, content: Dict) -> Dict:
        cleaned = {k: v for k, v in content.items() if v is not None}
        msg = {"role": "tool", "tool_call_id": tool_call_id, "_kind": "tool_result", "content": json.dumps(cleaned, sort_keys=True)}
        self.messages.append(msg)
        return msg

    def append_system_note(self, content: str) -> Dict:
        msg = {"role": "system", "content": content, "_kind": "system_note"}
        self.messages.append(msg)
        return msg

    def upsert_reference(self, ref: Dict[str, Any]) -> None:
        content = json.dumps(ref, ensure_ascii=False, default=str, sort_keys=True)
        self.reference_data_msg = {
            "role": "user", "_kind": "database_reference",
            "content": f"[DATABASE REFERENCE]\n{content}\n[/DATABASE REFERENCE]",
        }

    @staticmethod
    def _df_label(name: str, meta: Any) -> str:
        if not isinstance(meta, dict):
            return name
        suffix = " (TRUNCATED)" if meta.get("truncated") else ""
        cols = ", ".join(meta.get("columns") or [])
        return f"{name}{suffix} [{cols}]" if cols else f"{name}{suffix}"

    def build_available_dataframes(self, session_dataframes: Dict[str, Any]) -> Dict[str, Any]:
        annotated = [Context._df_label(name, meta) for name, meta in sorted(session_dataframes.items())]
        return {"available_dataframes": annotated}

    def upsert_available_dataframes(self, session_dataframes: Dict[str, Any]) -> None:
        content = json.dumps(self.build_available_dataframes(session_dataframes), ensure_ascii=False, default=str, sort_keys=True)
        self.available_dataframes_msg = {
            "role": "user", "_kind": "available_dataframes",
            "content": f"[AVAILABLE DATAFRAMES]\n{content}\n[/AVAILABLE DATAFRAMES]",
        }

    # --- Memory ledger (durable facts across steps & turns) ---------------

    def log_error(self, tool: str, error_msg: str) -> None:
        clean = error_msg.split('\n')[0][:150]
        entry = f"{tool} failed: {clean}"
        if not self.recent_errors or self.recent_errors[-1] != entry:
            self.recent_errors.append(entry)
            cap = get_settings().RECENT_ERRORS_MAX
            if len(self.recent_errors) > cap:
                self.recent_errors.pop(0)

    def log_describe(self, tables: List[str]) -> None:
        if tables:
            self.tool_trail.append(f"[D:{','.join(tables[:8])}]")
            for t in tables:
                tl = t.lower()
                if tl not in self.tables_touched and len(self.tables_touched) < MAX_REFERENCED_TABLES:
                    self.tables_touched.append(tl)
        self._trim_trail()

    def log_sql(self, queries: List[Dict[str, Any]], tables: List[str], status: str = "success", error: str = "") -> None:
        if status == "error" and error:
            self.log_error("execute_sql", error)
        if queries:
            names = [q.get("df_name", "?") for q in queries[:4]]
            more = f"+{len(queries) - 4}" if len(queries) > 4 else ""
            self.tool_trail.append(f"[SQL:{status}:{len(queries)}Q:{','.join(names)}{more}]")
        for t in tables:
            tl = t.lower() if isinstance(t, str) else t
            if tl not in self.tables_touched and len(self.tables_touched) < MAX_REFERENCED_TABLES:
                self.tables_touched.append(tl)
        self._trim_trail()

    def log_python(self, code: str, output_excerpt: str = "", status: str = "success", error: str = "") -> None:
        if status == "error" and error:
            self.log_error("run_python", error)
        entry = f"[Py:{len(code)}c:{status}]"
        if error:
            entry += f" !{error[:MEMORY_OUTPUT_EXCERPT_CHARS]}"
        elif output_excerpt:
            entry += f" -> {output_excerpt[:MEMORY_OUTPUT_EXCERPT_CHARS]}"
        self.tool_trail.append(entry)
        self._trim_trail()

    def _trim_trail(self) -> None:
        if len(self.tool_trail) > MAX_CODE_LOG_ENTRIES:
            dropped = len(self.tool_trail) - MAX_CODE_LOG_ENTRIES
            self.tool_trail = self.tool_trail[-MAX_CODE_LOG_ENTRIES:]
            if dropped:
                logger.debug("Context: trimmed %d old tool_trail entries", dropped)

    # --- Logging helpers --------------------------------------------------

    def log_summary(self, live_messages: Optional[List[Dict[str, Any]]] = None) -> Dict[str, Any]:
        """Structured, read-only view of what's being kept — meant for logs."""
        msgs = live_messages if live_messages is not None else self.messages
        by_role = {"system": 0, "user": 0, "assistant_final": 0, "assistant_tool_call": 0, "tool": 0}
        chars = 0
        step_context_present = False
        reference_present = False
        available_data_present = False
        for m in msgs:
            role = m.get("role")
            content = m.get("content")
            if isinstance(content, str):
                chars += len(content)
            if role == "assistant" and m.get("tool_calls"):
                by_role["assistant_tool_call"] += 1
            elif role == "assistant":
                by_role["assistant_final"] += 1
            elif role == "user":
                by_role["user"] += 1
                if isinstance(content, str) and "[STEP CONTEXT]" in content:
                    step_context_present = True
                if isinstance(content, str) and "[DATABASE REFERENCE]" in content:
                    reference_present = True
                if isinstance(content, str) and "[AVAILABLE DATAFRAMES]" in content:
                    available_data_present = True
            elif role in by_role:
                by_role[role] += 1
        return {
            "message_count": len(msgs),
            "by_role": by_role,
            "approx_chars": chars,
            "step_context_present": step_context_present,
            "reference_present": reference_present or (self.reference_data_msg is not None),
            "available_data_present": available_data_present or (self.available_dataframes_msg is not None),
            "memory": {
                "tool_trail_entries": len(self.tool_trail),
                "tables_touched": len(self.tables_touched),
                "verified_facts_count": len(self.verified_facts),
            },
        }

    # --- Step context (per-step metadata) ---------------------------------

    def _build_step_context(self, steps_used_this_turn: int, max_steps: int,
                            available_dataframes: Optional[Union[List[str], Dict[str, Any]]] = None) -> Dict[str, Any]:
        result: Dict[str, Any] = {
            "current_time": _current_time_str(),
            "tool_trail": [str(e)[:300] for e in self.tool_trail[-5:]],
            "recent_errors": [str(e)[:500] for e in self.recent_errors],
            "verified_facts": self.verified_facts,
            "progress": {
                "step": steps_used_this_turn,
                "max_steps": max_steps,
                "remaining": max_steps - steps_used_this_turn,
            },
        }
        if available_dataframes:
            if isinstance(available_dataframes, dict):
                labels = [Context._df_label(name, meta) for name, meta in sorted(available_dataframes.items())]
            else:
                labels = list(available_dataframes)
            result["available_dataframes"] = labels
        return result

    # --- Token accounting (N+1 model) ------------------------------------

    def record_real_usage(self, prompt_tokens: int, sent_messages: List[Dict[str, Any]]) -> None:
        """Feed back the API's exact prompt token count for the messages that
        were actually sent. All later budget decisions are derived from these
        real measurements (see TokenCalibrator)."""
        self.calibrator.record_real_usage(prompt_tokens, _chars_of(sent_messages))

    def estimated_tokens(self) -> int:
        """Calibrated estimate of the current message list (0 until the first
        real measurement of this session arrives — nothing is trimmed before
        it)."""
        return self.calibrator.estimate_many(self.messages)

    @staticmethod
    def _fallback_kind(m: Dict) -> str:
        """Infer message kind from role + content for old sessions without _kind."""
        role = m.get("role", "")
        content = str(m.get("content", ""))
        if role == "system":
            return "system_note" if "Step limit reached" in content else "system"
        if role == "user":
            if "[DATABASE REFERENCE]" in content:
                return "database_reference"
            if "[AVAILABLE DATAFRAMES]" in content:
                return "available_dataframes"
            if "[STEP CONTEXT]" in content:
                return "step_context"
            if "[CONTEXT SUMMARY]" in content:
                return "summary"
            return "user_followup"
        if role == "assistant":
            return "assistant_tool_round" if m.get("tool_calls") else "assistant_final"
        if role == "tool":
            return "tool_result"
        return "unknown"

    @staticmethod
    def _trim_to_budget(messages: List[Dict[str, Any]], max_tokens: int, calibrator: "TokenCalibrator") -> List[Dict[str, Any]]:
        if max_tokens <= 0 or not messages:
            return list(messages)

        n = len(messages)
        blocks = []
        i = 0
        has_original_query = False

        while i < n:
            m = messages[i]
            kind = Context._kind(m)

            if kind == "system" or kind == "system_note":
                blocks.append(("keep", [i], calibrator.estimate(m)))
                i += 1
                continue

            if kind == "database_reference" or kind == "available_dataframes":
                blocks.append(("keep", [i], calibrator.estimate(m)))
                i += 1
                continue

            if kind == "user_query" or (kind == "user_followup" and not has_original_query):
                has_original_query = True
                blocks.append(("keep", [i], calibrator.estimate(m)))
                i += 1
                continue

            if kind == "user_followup":
                blocks.append(("user_followup", [i], calibrator.estimate(m)))
                i += 1
                continue

            if kind == "assistant_tool_round":
                j = i
                round_tokens = calibrator.estimate(messages[j])
                while j + 1 < n and Context._kind(messages[j + 1]) == "tool_result":
                    j += 1
                    round_tokens += calibrator.estimate(messages[j])
                blocks.append(("round", list(range(i, j + 1)), round_tokens))
                i = j + 1
                continue

            if kind == "assistant_final" or kind == "summary":
                blocks.append(("final_answer", [i], calibrator.estimate(m)))
                i += 1
                continue

            blocks.append(("drop", [i], calibrator.estimate(m)))
            i += 1

        newest_round_idx = None
        for bidx in range(len(blocks) - 1, -1, -1):
            if blocks[bidx][0] == "round":
                newest_round_idx = bidx
                break

        kept_blocks = []
        running_tokens = 0

        for bidx in range(len(blocks) - 1, -1, -1):
            btype, bindices, btokens = blocks[bidx]

            if btype == "keep":
                kept_blocks.append(blocks[bidx])
                running_tokens += btokens
                continue

            is_newest_block = (bidx == len(blocks) - 1)
            is_newest_round = (btype == "round" and bidx == newest_round_idx)

            if is_newest_block or is_newest_round:
                kept_blocks.append(blocks[bidx])
                running_tokens += btokens
                continue

            if btype != "drop" and running_tokens + btokens <= max_tokens:
                kept_blocks.append(blocks[bidx])
                running_tokens += btokens

        kept_indices: Set[int] = set()
        for _, indices, _ in sorted(kept_blocks, key=lambda x: x[1][0]):
            kept_indices.update(indices)

        return [messages[i] for i in range(n) if i in kept_indices]

    # --- Request assembly (build_request + compact) -----------------------

    STEP_CONTEXT_HEADROOM = 4000

    def build_request(self, max_tokens: int = 0, step_context: Optional[Dict[str, Any]] = None, progress_notes: Optional[List[str]] = None) -> List[Dict[str, Any]]:
        # Cache-hit-friendly ordering: stable blocks first, most-mutating last.
        # The reference is semi-stable (rebuilt only when new tables are
        # touched), so it sits in the keep tier right after system where its
        # tokens are prefix-cached between rebuilds instead of re-sent every
        # step as a miss.
        pipeline = ContextPipeline(estimator=self.calibrator)
        pipeline.keep("system", self.messages[:1])
        if self.reference_data_msg is not None:
            pipeline.keep("reference", [self.reference_data_msg])
        if self.available_dataframes_msg is not None:
            pipeline.keep("available_dataframes", [self.available_dataframes_msg])
        pipeline.trim("history", self.messages[1:])

        tail = []
        if step_context:
            state_content = json.dumps(step_context, ensure_ascii=False, default=str, sort_keys=True)
            tail_content = f"[STEP CONTEXT]\n{state_content}\n[/STEP CONTEXT]"
            if progress_notes:
                tail_content += "\n\n" + "\n".join(progress_notes)
            tail.append({"role": "user", "content": tail_content})
        pipeline.tail("step_context", tail)

        trim_budget = max(self.STEP_CONTEXT_HEADROOM, max_tokens - self.STEP_CONTEXT_HEADROOM) if max_tokens > 0 else 0
        return pipeline.build(trim_budget)

    def compact(self, max_tokens: int = 0) -> None:
        self.messages = Context.flatten_tool_rounds(self.messages)
        self.messages = [m for m in self.messages if Context._kind(m) != "system_note"]

        # The reference is carried by reference_data_msg (persisted/restored
        # separately) and injected at request time — embedding it here too made
        # it appear twice on resumed turns.
        pipeline = ContextPipeline(estimator=self.calibrator)
        pipeline.keep("system", self.messages[:1])
        pipeline.trim("history", self.messages[1:])
        self.messages = pipeline.build(max_tokens)

    @staticmethod
    def flatten_tool_rounds(messages: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        n = len(messages)
        out: List[Dict[str, Any]] = []
        i = 0
        while i < n:
            m = messages[i]
            if Context._kind(m) == "assistant_tool_round":
                tool_names = {
                    tc.get("id"): tc.get("function", {}).get("name", "tool")
                    for tc in m.get("tool_calls", [])
                }
                parts = []
                if m.get("content"):
                    parts.append(str(m["content"]))
                j = i
                while j + 1 < n and messages[j + 1].get("role") == "tool":
                    j += 1
                    tm = messages[j]
                    name = tool_names.get(tm.get("tool_call_id"), "tool")
                    parts.append(f"[{name} result]\n{tm.get('content', '')}")
                out.append({"role": "assistant", "content": "\n\n".join(parts), "_kind": "assistant_final"})
                i = j + 1
                continue
            out.append(m)
            i += 1
        return out

    # --- Summarization (between-turn context compression) -----------------

    def summarize(self, summary_text: str, structured_state: Optional[Dict[str, Any]] = None) -> None:
        last_user_idx = -1
        for i in range(len(self.messages) - 1, -1, -1):
            if self.messages[i].get("role") == "user":
                last_user_idx = i
                break
        if last_user_idx < 2:
            return
        foundation_end = 2
        content = f"[CONTEXT SUMMARY]\n{summary_text}\n[/CONTEXT SUMMARY]"
        if structured_state:
            content += f"\n\n[STRUCTURED STATE]\n{json.dumps(structured_state, ensure_ascii=False, default=str, indent=2)}\n[/STRUCTURED STATE]"
        summary_msg = {
            "role": "user", "_kind": "summary",
            "content": content,
        }
        self.messages = self.messages[:foundation_end] + [summary_msg] + self.messages[last_user_idx:]


# ── Token calibrator ─────────────────────────────────────────────────────


def _chars_of(messages: List[Dict[str, Any]]) -> int:
    return sum(len(json.dumps(m, ensure_ascii=False, default=str)) for m in messages)


class TokenCalibrator:
    """Usage-calibrated token estimation for message budget decisions (N+1).

    No heuristic formula: until the API reports the first real prompt token
    count for this session, nothing is trimmed (the first request of a turn
    goes out whole — it is the smallest context anyway). After every LLM
    step, `record_real_usage()` feeds back the exact server count and a
    rolling tokens-per-char density (EMA) drives all later budget decisions.
    """

    def __init__(self):
        self.tokens_per_char: Optional[float] = None

    @property
    def calibrated(self) -> bool:
        return self.tokens_per_char is not None

    def record_real_usage(self, prompt_tokens: int, sent_chars: int) -> None:
        if prompt_tokens <= 0 or sent_chars <= 0:
            return
        measured = prompt_tokens / sent_chars
        if self.tokens_per_char is None:
            self.tokens_per_char = measured
        else:
            self.tokens_per_char = 0.7 * self.tokens_per_char + 0.3 * measured

    def estimate(self, msg: Dict[str, Any]) -> int:
        if self.tokens_per_char is None:
            return 0
        text = json.dumps(msg, ensure_ascii=False, default=str)
        return max(1, int(len(text) * self.tokens_per_char))

    def estimate_many(self, messages: List[Dict[str, Any]]) -> int:
        return sum(self.estimate(m) for m in messages)

    def snapshot(self) -> Optional[float]:
        return self.tokens_per_char

    def restore(self, tokens_per_char: Optional[float]) -> None:
        self.tokens_per_char = tokens_per_char


# ── Declarative assembly pipeline ────────────────────────────────────────


class ContextPipeline:
    """Declarative assembly pipeline for LLM request messages.

    Tiers are processed in registration order:
      1. "keep" — always included, never trimmed
      2. "trim" — included subject to budget (newest-first priority)
      3. "late" — always included after trimmed history, before the tail
      4. "tail" — always appended at the very end

    "keep" blocks (e.g. the DDL reference) sit right after system so their
    stable tokens stay inside the prompt-cache prefix until they rebuild; a
    rebuild only invalidates the prefix from that block onward. Like "late",
    "keep" is not counted against the trim budget.

    Budget flows: max_tokens → trim tiers (after reserving tail tokens).
    """

    def __init__(self, estimator: Optional[TokenCalibrator] = None):
        self._tiers: List[tuple] = []
        self._estimator = estimator or TokenCalibrator()

    def _estimate_tokens(self, messages: List[Dict]) -> int:
        return self._estimator.estimate_many(messages)

    def keep(self, name: str, messages: List[Dict]) -> "ContextPipeline":
        self._tiers.append(("keep", name, list(messages)))
        return self

    def trim(self, name: str, messages: List[Dict]) -> "ContextPipeline":
        self._tiers.append(("trim", name, list(messages)))
        return self

    def tail(self, name: str, messages: List[Dict]) -> "ContextPipeline":
        self._tiers.append(("tail", name, list(messages)))
        return self

    def late(self, name: str, messages: List[Dict]) -> "ContextPipeline":
        self._tiers.append(("late", name, list(messages)))
        return self

    def build(self, max_tokens: int = 0) -> List[Dict]:
        prefix: List[Dict] = []
        trim_msgs: List[Dict] = []
        late_msgs: List[Dict] = []
        tail: List[Dict] = []

        for strategy, _name, msgs in self._tiers:
            items = copy.deepcopy(msgs) if msgs else []
            if strategy == "keep":
                prefix.extend(items)
            elif strategy == "trim":
                trim_msgs.extend(items)
            elif strategy == "late":
                late_msgs.extend(items)
            elif strategy == "tail":
                tail.extend(items)

        if tail and max_tokens > 0:
            tail_cost = self._estimate_tokens(tail)
            trim_budget = max(0, max_tokens - tail_cost)
        else:
            trim_budget = max_tokens

        if trim_msgs and trim_budget > 0:
            trimmed = Context._trim_to_budget(trim_msgs, trim_budget, self._estimator)
            prefix.extend(trimmed)

        prefix.extend(late_msgs)
        prefix.extend(tail)
        return prefix
