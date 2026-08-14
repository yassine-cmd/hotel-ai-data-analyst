"""Agent orchestration: session lifecycle, context management, LLM streaming
setup, and event-driven analysis. The per-step reasoning/tool loop is
delegated to step_loop.py (_execute_turn).

Sections in this file:
   30- 70   Agent.__init__, _build_reference, _convergence_notes
   72-144   Prompt diagnostics, _schema_index, _parse_tool_args, _error
  146-274   SYSTEM_PROMPT, TokenCounter
  276-315   _format_business_context, _fresh_session_state, _summarize_context
  317-400   analyze() — guard/schema setup, session resume, turn lock
  400-420   analyze() — context dict, yield status, delegate _execute_turn
  420-480   analyze() — persist, done event, cleanup
"""

import logging
import json
import uuid
import asyncio
from dataclasses import dataclass, field, fields
from pathlib import Path
from typing import List, Dict, Any, Optional, Set, Tuple, AsyncGenerator

from .db import Budget, compile_ddl, QueryExecutor
from .llm import LLM
from .tools import (SQLTool, PythonTool, DescribeTool, QuestionTool, ChartTool,
                     build_tool_schemas, SensitiveDataGuard, StandardToolResult)
from .tools._helpers import _enforce_df_budget
from .context import Context
from .errors import stream_error
from .events import phase, status, summary as ev_summary, done
from .interfaces import ClientStore, SandboxClient, StorageProvider
from .step_loop import _execute_turn, _dispatch, _stream_llm
from config import get_settings, PREINJECTED_PACKAGES, BANNED_PACKAGES

logger = logging.getLogger(__name__)

# Agent loop configuration — driven by Settings (env), never silently falling
# back to defaults: if AGENT_MAX_STEPS / AGENT_TOOL_TIMEOUT are absent the
# Settings defaults apply; if the whole config is invalid, get_settings() raises
# loudly at import instead of running with guessed values.
MAX_STEPS = get_settings().AGENT_MAX_STEPS
TOOL_TIMEOUT = get_settings().AGENT_TOOL_TIMEOUT


_PROMPT_DIR = Path(__file__).resolve().parent.parent / "prompts"

# Prompt is kept out of source (prompts/system_prompt.md) so it is versioned
# and diffable. Placeholders are resolved ONCE at import so the rendered system
# message is a stable, process-wide cache prefix. Cache-conscious ordering:
# the provider caches the message prefix left-to-right, so immutable rules
# live first and variable content ([ENVIRONMENT FACTS], and the per-client
# [BUSINESS CONTEXT] block appended in _fresh_session_state) is pushed to the
# end — the more a block drifts, the later it sits.
def _load_system_prompt() -> str:
    return (_PROMPT_DIR / "system_prompt.md").read_text(encoding="utf-8")


SYSTEM_PROMPT = _load_system_prompt()

# Resolve single-source placeholders (package list + step budget + env facts)
# so the prompt and the tool descriptions can never drift.
SYSTEM_PROMPT = (
    SYSTEM_PROMPT
    .replace("{PKGS}", ", ".join(PREINJECTED_PACKAGES))
    .replace("{BANNED}", ", ".join(BANNED_PACKAGES))
    .replace("{STEPS}", str(MAX_STEPS))
    .replace("{DATAFRAMES_MAX}", str(get_settings().SESSION_DATAFRAMES_MAX))
    .replace("{CURRENCY}", get_settings().CURRENCY)
    .replace("{DATA_SINCE}", get_settings().DATA_SINCE)
)


@dataclass
class TokenCounter:
    # Single source of truth for the context ceiling: derives from
    # Settings.CONTEXT_LIMIT so it can never drift from the config.
    context_limit: int = field(default_factory=lambda: get_settings().CONTEXT_LIMIT)
    total_prompt: int = 0
    total_completion: int = 0
    total_thinking: int = 0
    total_tokens: int = 0
    total_cache_hit: int = 0
    total_cache_miss: int = 0
    total_cost: float = 0.0
    step_prompt: int = 0
    step_completion: int = 0
    step_thinking: int = 0
    step_total: int = 0
    step_cache_hit: int = 0
    step_cache_miss: int = 0
    step_cost: float = 0.0

    def record_step(self, usage) -> None:
        self.step_prompt = getattr(usage, "prompt_tokens", 0) or 0
        self.step_completion = getattr(usage, "completion_tokens", 0) or 0
        self.step_total = getattr(usage, "total_tokens", 0) or 0
        details = getattr(usage, "completion_tokens_details", None)
        self.step_thinking = (getattr(details, "reasoning_tokens", 0) or 0) if details else 0
        # DeepSeek-style cache fields (prompt_cache_hit/miss_tokens) vs
        # OpenAI-style (prompt_tokens_details.cached_tokens): gateways like
        # OpenCode Zen report only the OpenAI-style field, so fall back to
        # it when the DeepSeek fields are absent.
        hit = getattr(usage, "prompt_cache_hit_tokens", 0) or 0
        if hit == 0:
            pd = getattr(usage, "prompt_tokens_details", None)
            hit = (getattr(pd, "cached_tokens", 0) or 0) if pd is not None else 0
        # Provider contract is prompt = hit + miss. Even when the provider
        # reports no cache fields at all (cold request, compression call), the
        # full prompt was still sent uncached, so it is billed at the miss
        # rate. Deriving miss from prompt keeps cost accurate (prompt tokens
        # are never charged $0) and makes cache_hit_ratio = hit / prompt.
        self.step_cache_hit = hit
        self.step_cache_miss = max(0, self.step_prompt - hit)
        # Billed cost reported by the gateway (OpenRouter usage.cost, USD).
        # Authoritative — bakes in provider routing, cache discounts, and
        # reasoning-token billing that a local price table cannot reproduce.
        # OpenRouter returns it in the final streamed chunk; other providers
        # may omit it, in which case the cost stays 0 and the ledger only
        # sums what the gateway actually billed.
        self.step_cost = float(getattr(usage, "cost", 0.0) or (getattr(usage, "model_extra", None) or {}).get("cost", 0.0) or 0.0)
        self.total_cost += self.step_cost
        self.total_prompt += self.step_prompt
        self.total_completion += self.step_completion
        self.total_thinking += self.step_thinking
        self.total_tokens += self.step_total
        self.total_cache_hit += self.step_cache_hit
        self.total_cache_miss += self.step_cache_miss

    @property
    def usage_pct(self) -> float:
        return self.step_prompt / self.context_limit if self.step_prompt else 0.0

    @property
    def cache_hit_ratio(self) -> float:
        """Fraction of prompt tokens served from the provider's prefix cache
        (hit / (hit + miss)). 0.0 before any usage is recorded."""
        total = self.total_cache_hit + self.total_cache_miss
        return self.total_cache_hit / total if total else 0.0

    def log_step(self, step: int) -> None:
        logger.info("TOKENS step=%d | sent=%d recv=%d (thinking=%d) cache_hit=%d cache_miss=%d cost=%.6f | session_total=%d session_cost=%.6f", step, self.step_prompt, self.step_completion, self.step_thinking, self.step_cache_hit, self.step_cache_miss, self.step_cost, self.total_tokens, self.total_cost)

    def step_summary(self) -> str:
        return f"prompt={self.step_prompt} completion={self.step_completion} (thinking={self.step_thinking}) cache_hit={self.step_cache_hit} cache_miss={self.step_cache_miss} cost={self.step_cost:.6f} total={self.step_total}"

    def cumulative_summary(self) -> str:
        return f"prompt={self.total_prompt} completion={self.total_completion} (thinking={self.total_thinking}) cache_hit={self.total_cache_hit} cache_miss={self.total_cache_miss} cost={self.total_cost:.6f} total={self.total_tokens}"


class Agent:
    def __init__(self, sandbox: SandboxClient, llm: LLM, tool_timeout: int = TOOL_TIMEOUT, max_steps: int = MAX_STEPS, executor: Optional[QueryExecutor] = None, tools: Optional[Dict[str, object]] = None, session_manager: Optional[ClientStore] = None, asset_manager: Optional[StorageProvider] = None, guard: Optional[SensitiveDataGuard] = None):
        self.executor = executor
        self.sandbox = sandbox
        self.llm = llm
        self._guard = guard
        self._tool_timeout = tool_timeout
        self.max_steps = max_steps
        self._session_manager = session_manager
        self._asset_manager = asset_manager
        if tools is not None:
            self._tools = dict(tools)
        else:
            self._tools = {"describe_table": DescribeTool(), "execute_sql": SQLTool(), "run_python": PythonTool(), "question": QuestionTool(), "create_chart_spec": ChartTool()}
        self._tool_schemas = build_tool_schemas(list(self._tools.values()))
        self._prev_diag_messages: List[Dict[str, Any]] | None = None
        self._business_context: List[Dict[str, Any]] = []
        logger.info("Agent initialized | tools=%s | timeout=%ds", list(self._tools.keys()), self._tool_timeout)

    def set_guard(self, guard: SensitiveDataGuard) -> None:
        self._guard = guard
        logger.info("SensitiveDataGuard re-initialized | sensitive_tables=%d", len(guard.sensitive_tables))

    def _build_reference(self, referenced_tables: List[str], guard: Optional[SensitiveDataGuard] = None, schema_dict: Optional[Dict[str, Any]] = None, business_context: Optional[List[Dict]] = None, unauthorized=()) -> Dict[str, Any]:
        """Semi-stable schema reference (DDL, accessible columns, sensitive
        rules) for the tables referenced so far.

        Rebuilt (via ctx.upsert_reference) ONLY when the referenced-table set
        changes. Context.build_request places this tier in the keep blocks
        right after system so its stable tokens are prefix-cached between
        rebuilds; a rebuild simply re-emits the block at the same position.

        Two distinct access categories are surfaced so the model can explain
        a blocked table honestly:
          * sensitive  (guard) — regulated personal data, blocked for EVERY
            user (admins included); a grant can never override this.
          * permission (`unauthorized`) — table not granted to this user's
            tokens. Admin users have no unauthorized tables.
        """
        ref: Dict[str, Any] = {}
        bc = self._business_context if business_context is None else business_context
        effective_guard = guard or self._guard
        unauthorized_lower = {str(t).lower() for t in (unauthorized or ())}

        blocked = {}
        acc = {}
        if referenced_tables:
            for t in referenced_tables:
                is_sensitive_blocked = effective_guard is not None and effective_guard.is_sensitive_table(t)
                is_perm_blocked = str(t).lower() in unauthorized_lower
                if is_sensitive_blocked or is_perm_blocked:
                    is_sensitive = any(
                        str(k).lower() == str(t).lower() and bool(v.get("is_sensitive"))
                        for k, v in (schema_dict or {}).items()
                    )
                    blocked[t] = {
                        "queryable": False,
                        "accessible_columns": [],
                        "reason": (
                            "Table holds regulated personal data — not queryable by any user"
                            if is_sensitive
                            else "Table is not granted to this user's permissions"
                        ),
                    }
                else:
                    if effective_guard is not None:
                        cols = effective_guard.accessible_columns(t)
                        if cols:
                            acc[t] = cols
        if effective_guard is not None:
            ref["sensitive_tables"] = effective_guard.sensitive_tables
        if blocked:
            ref["blocked_tables"] = blocked
        if acc:
            ref["accessible_columns"] = acc

        # DDL for referenced tables (bounded by MAX_REFERENCED_TABLES in context.py)
        if not schema_dict:
            return ref
        if referenced_tables:
            bc_map = Agent._scoped_business_context_map(bc)
            ddl_parts = []
            for table in referenced_tables:
                if table not in schema_dict:
                    continue
                if effective_guard is not None and effective_guard.is_sensitive_table(table):
                    continue
                if str(table).lower() in unauthorized_lower:
                    continue
                subset = {table: schema_dict[table]}
                ddl_parts.append(compile_ddl(subset, business_context_map=bc_map))
            if ddl_parts:
                ref["table_ddl"] = "\n\n".join(ddl_parts)
        return ref

    def _convergence_notes(self, ctx: Context, steps_used_this_turn: int) -> List[str]:
        """Plain-English nudges appended after the [STEP CONTEXT] JSON block
        (see Context.build_request). Trailing imperative sentences are followed
        more reliably than nested JSON fields.

        Both the step-budget warning and a directive can fire in the same
        step (e.g. past the halfway point AND repeating an error) — that's
        preserved here as multiple lines rather than one overwriting the other.
        """
        notes: List[str] = []
        half_step = max(1, self.max_steps // 2)
        if steps_used_this_turn >= half_step:
            notes.append(f"You have used {steps_used_this_turn}/{self.max_steps} steps. Prioritize convergence — use existing DataFrames.")
        if steps_used_this_turn >= self.max_steps - 2:
            notes.append("Final steps. Produce your best answer now using existing data.")
        if steps_used_this_turn >= self.max_steps - 1:
            notes.append("This is your last step. Output your final answer now — no more tool calls are possible.")
        if len(ctx.recent_errors) >= 2 and ctx.recent_errors[-1] == ctx.recent_errors[-2]:
            notes.append("You are repeating the same error. Stop and summarize what you know, or change approach now.")
        return notes

    # ── Prompt diagnostics (Phase 0a) ─────────────────────────────────

    _DIAG_ZONE_LABELS = {"system", "reference", "dataframes", "history", "step_context"}

    @staticmethod
    def _classify_diag_message(msg: Dict[str, Any]) -> str:
        content = str(msg.get("content", ""))
        if msg.get("role") == "system":
            return "system"
        if "[STEP CONTEXT]" in content:
            return "step_context"
        if "[DATABASE REFERENCE]" in content:
            return "reference"
        if "[AVAILABLE DATAFRAMES]" in content:
            return "dataframes"
        return "history"

    @staticmethod
    def _log_prompt_diagnostics(logger: logging.Logger, messages: List[Dict[str, Any]],
                                  prev_messages: List[Dict[str, Any]] | None,
                                  step: int, calibrator) -> None:
        zones: Dict[str, int] = {k: 0 for k in Agent._DIAG_ZONE_LABELS}
        for msg in messages:
            zone = Agent._classify_diag_message(msg)
            zones[zone] += calibrator.estimate(msg)
        logger.info("ZONES step=%d | tokens=%s", step, zones)

        if prev_messages is not None:
            prev_zones: Dict[str, int] = {k: 0 for k in Agent._DIAG_ZONE_LABELS}
            for msg in prev_messages:
                zone = Agent._classify_diag_message(msg)
                prev_zones[zone] += calibrator.estimate(msg)
            if zones != prev_zones:
                for i, (cur, prev) in enumerate(zip(messages, prev_messages)):
                    if cur != prev:
                        zone = Agent._classify_diag_message(messages[i])
                        logger.info("DIFF step=%d | first_diff_msg=%d | zone=%s", step, i, zone)
                        break

    @staticmethod
    def _schema_index(schema_dict: Dict[str, Any], blocked_tables=()) -> str:
        blocked = {str(t).lower() for t in blocked_tables}
        lines = []
        for table, info in sorted(schema_dict.items()):
            bits = [f"  {table}"]
            if info.get("is_sensitive"):
                bits.append("[SENSITIVE/BLOCKED]")
                lines.append(" ".join(bits))
                continue
            if str(table).lower() in blocked:
                bits.append("[NOT IN USER PERMISSIONS]")
                lines.append(" ".join(bits))
                continue
            row_count = info.get("row_count")
            cols = info.get("columns", [])
            if row_count is not None:
                bits.append(f"({row_count:,} rows, {len(cols)} cols)")
            else:
                bits.append(f"({len(cols)} cols)")
            desc = info.get("description")
            if desc:
                bits.append(f"— {desc}")
            lines.append(" ".join(bits))
        return f"Tables ({len(lines)} total):\n" + "\n".join(lines)

    @staticmethod
    def _parse_tool_args(raw_args: str) -> Optional[Dict[str, Any]]:
        try:
            return json.loads(raw_args) if raw_args else {}
        except json.JSONDecodeError:
            logger.warning("Tool args failed to parse as JSON | raw=%.300s", raw_args)
            return None

    @staticmethod
    def _error(message: str, context: Dict) -> StandardToolResult:
        return StandardToolResult(
            status="infra_error",
            tool="",
            summary=message,
            data={"status": "error", "error_kind": "infra", "message": message, "available_dfs": list(context.get("session_dataframes", {}).keys())},
            ui_data={"error": message},
        )

    @staticmethod
    def _format_business_context(entries: Optional[List[Dict]], max_chars: int = 6000) -> str:
        """Render admin-authored business context (glossary terms, rules,
        notes, conventions) as a single [BUSINESS CONTEXT] block.

        Only active entries are included, ordered by title. Scoped entries
        (with a scope_table) are excluded here — they ride into the per-table
        DDL in [DATABASE REFERENCE] instead (see _build_reference).
        """
        if not entries:
            return ""
        active = [
            e for e in entries
            if e.get("is_active", True) and not e.get("scope_table")
        ]
        if not active:
            return ""
        active.sort(key=lambda e: (e.get("title") or "").casefold())
        lines = ["[BUSINESS CONTEXT]"]
        used = len(lines[0]) + 1
        skipped = 0
        for e in active:
            title = (e.get("title") or "").strip()
            content = (e.get("content") or "").strip()
            if not title or not content:
                continue
            rendered = f"- {title}: {content}".replace("\r\n", "\n")
            if used + len(rendered) > max_chars:
                skipped += 1
                continue
            lines.append(rendered)
            used += len(rendered) + 1
        if len(lines) == 1:
            return ""
        if skipped:
            lines.append(f"[{skipped} business-context entr{'y' if skipped == 1 else 'ies'} omitted — beyond context budget]")
        lines.append("[/BUSINESS CONTEXT]")
        return "\n".join(lines)

    @staticmethod
    def _scoped_business_context_map(entries: Optional[List[Dict]]) -> Dict[str, List[Dict]]:
        """Group active business-context entries by scope_table (lowercased),
        ordered by title. Used to attach entries to the DDL of the table they
        apply to.
        """
        by_table: Dict[str, List[Dict]] = {}
        if not entries:
            return by_table
        for e in entries:
            if not e.get("is_active", True):
                continue
            table = e.get("scope_table")
            if not table:
                continue
            by_table.setdefault(str(table).lower(), []).append(e)
        for lst in by_table.values():
            lst.sort(key=lambda e: (e.get("title") or "").casefold())
        return by_table

    @staticmethod
    def _fresh_session_state(settings, schema_idx: str, query: str, business_context: Optional[List[Dict]] = None):
        """Build a brand-new session context, token tracker and budget.

        Shared by the 'no session' and 'no session manager' branches, which were
        previously three byte-for-byte identical inline constructions.
        """
        bc_block = Agent._format_business_context(business_context or [])
        system_prompt = SYSTEM_PROMPT
        if bc_block:
            system_prompt = SYSTEM_PROMPT + "\n\n" + bc_block
        ctx = Context(system_prompt=system_prompt, user_content=f"Database schema:\n{schema_idx}\n\nQuery:\n{query}")
        tracker = TokenCounter(context_limit=settings.CONTEXT_LIMIT)
        budget = Budget(query_limit=settings.SESSION_QUERY_LIMIT, row_limit=settings.SESSION_ROW_LIMIT, time_limit_seconds=settings.SESSION_TIME_LIMIT_SECONDS)
        return ctx, tracker, budget

    async def _summarize_context(self, ctx: Context, session_dataframes: Optional[Dict[str, Any]] = None, tracker: Optional[TokenCounter] = None, session_id: Optional[str] = None) -> Tuple[str, Dict[str, Any]]:
        active_dataframes = []
        if session_dataframes:
            for name, meta in session_dataframes.items():
                entry: Dict[str, Any] = {"name": name}
                if isinstance(meta, dict) and "shape" in meta:
                    entry["shape"] = meta["shape"]
                active_dataframes.append(entry)
        structured_state: Dict[str, Any] = {
            "verified_facts": ctx.verified_facts,
            "referenced_tables": list(ctx.tables_touched),
            "recent_errors": list(ctx.recent_errors),
            "user_intents": list(ctx.user_intents),
            "constraints": list(ctx.constraints),
            "active_dataframes": active_dataframes,
        }
        summarization_prompt = (
            "Summarize the following conversation between a user and an AI database analyst. "
            "Preserve: the user's goal, all constraints and filters, table names, column names, "
            "verified metrics and their values, errors that affect the current approach. "
            "Output a concise paragraph. Do not add invented facts."
        )
        foundation_end = 2
        last_user_idx = -1
        for i in range(len(ctx.messages) - 1, -1, -1):
            if ctx.messages[i].get("role") == "user":
                last_user_idx = i
                break
        if last_user_idx < foundation_end:
            return "", structured_state
        to_summarize = ctx.messages[foundation_end:last_user_idx]
        rendered = "\n\n".join(
            f"{m.get('role').upper()}: {m.get('content', '')}"
            for m in to_summarize if m.get("content")
        )
        summary_messages = [
            {"role": "system", "content": "You are a conversation summarizer."},
            {"role": "user", "content": f"{summarization_prompt}\n\nConversation:\n{rendered}"},
        ]
        summary_text = ""
        last_usage = None
        try:
            async for chunk in self.llm.stream_chat(summary_messages, tools=None, stop=None, session_id=session_id):
                if hasattr(chunk, "usage") and chunk.usage:
                    last_usage = chunk.usage
                if not chunk.choices:
                    continue
                delta = chunk.choices[0].delta
                if delta.content:
                    summary_text += delta.content
        except Exception:
            logger.exception("Summarization LLM call failed")
        # Record usage exactly once per request (see _stream_llm): streaming
        # repeats cumulative totals on each chunk, so record the last one.
        if last_usage is not None and tracker is not None:
            tracker.record_step(last_usage)
        if tracker is not None and tracker.step_prompt > 0:
            ctx.record_real_usage(tracker.step_prompt, summary_messages)
        return summary_text or "(conversation summary unavailable)", structured_state

    async def analyze(self, query: str, session_id: str, client_id: str, *,
                      schema: Optional[Dict[str, Any]] = None,
                      sensitive_rules: Optional[Dict[str, Any]] = None,
                      datasource_id: str = "__default__",
                      execution: Optional[Dict[str, Any]] = None,
                      agent_style: Optional[Dict[str, Any]] = None,
                      user_ref: Optional[int] = None,
                      user_access: Optional[Dict[str, Any]] = None,
                      executor: Optional[QueryExecutor] = None) -> AsyncGenerator[Dict[str, Any], None]:
        logger.info("=" * 60)
        logger.info("ANALYZE | client=%s session=%s | query=%s", client_id, session_id, query)

        # Per-session executor (one data-plane URL per client) overrides the
        # default so each client's SQL runs against its own Laravel instance.
        turn_executor = executor or self.executor

        guard = self._guard
        schema_dict: Dict[str, Any] = schema or {}
        sens_tables: Set[str] = set()
        unauthorized: Set[str] = set()
        if schema:
            sens_columns: Dict[str, List[str]] = {"*": []}
            if sensitive_rules:
                sens_tables = set(sensitive_rules.get("blocked_tables", []))
                raw_cols = sensitive_rules.get("blocked_columns", {})
                if isinstance(raw_cols, dict):
                    sens_columns = raw_cols
                elif isinstance(raw_cols, list):
                    sens_columns = {"*": raw_cols}
            if user_access and user_access.get("allowed_tables") is not None:
                allowed = {str(t).lower() for t in user_access.get("allowed_tables", [])}
                unauthorized = {t for t in schema_dict if str(t).lower() not in allowed}
            # Per-user PMS permission column allow-list (separate axis from the
            # global sensitive deny-set). {table: [cols]}; a table absent here is
            # unrestricted on columns. Fed to the guard as an *allow* set; the
            # guard computes allowed ∩ ¬sensitive (sensitive always wins).
            allowed_columns: Optional[Dict[str, List[str]]] = None
            if user_access and user_access.get("allowed_columns") is not None:
                raw_ac = user_access.get("allowed_columns", {})
                if isinstance(raw_ac, dict):
                    allowed_columns = {
                        str(t).lower(): [str(c).lower() for c in cols]
                        for t, cols in raw_ac.items()
                        if isinstance(cols, (list, tuple))
                    }
            # Global sensitivity and the per-user permission rules are kept
            # SEPARATE: the guard carries the global sensitive rules (they apply
            # to every user, admins included) AND the per-user permission
            # allow-list (column scoping from tokens). `unauthorized` expresses
            # which non-sensitive tables this user's tokens don't grant. A grant
            # can never override a sensitive table or column.
            guard = SensitiveDataGuard(
                lambda: schema_dict,
                sensitive_tables=lambda: sens_tables,
                sensitive_columns=lambda: sens_columns,
                allowed_columns=lambda: allowed_columns,
            )
            schema_idx = self._schema_index(schema_dict, blocked_tables=unauthorized) if schema_dict else ""
        else:
            if guard is None:
                guard = SensitiveDataGuard(lambda: {})
            schema_idx = self._schema_index(schema_dict) if schema_dict else ""

        settings = get_settings()
        live_budget = int(settings.CONTEXT_LIMIT * settings.CONTEXT_LIVE_FRACTION)
        persist_budget = int(settings.CONTEXT_LIMIT * settings.CONTEXT_PERSIST_FRACTION)
        session_dataframes: Dict[str, Any] = {}
        business_context: List[Dict] = (agent_style or {}).get("business_context", [])
        self._business_context = business_context

        turn_lock = None
        if self._session_manager is not None:
            session = await self._session_manager.get_or_create(session_id, client_id)
            turn_lock = session.get("lock")

            if session["context"] is not None:
                ctx = session["context"]
                ctx.append_user_query(f"Follow-up query:\n{query}")
                session_dataframes = session.get("dataframes", {})

                tracker = session["tracker"]
                if tracker is None:
                    tracker = TokenCounter(context_limit=settings.CONTEXT_LIMIT)
                elif isinstance(tracker, dict):
                    known = {f.name for f in fields(TokenCounter)}
                    filtered = {k: v for k, v in tracker.items() if k in known}
                    tracker = TokenCounter(**filtered)
                elif not hasattr(tracker, "record_step"):
                    tracker = TokenCounter()

                extra = ctx.calibrator.estimate_many([ctx.reference_data_msg]) if ctx.reference_data_msg is not None else 0
                if ctx.estimated_tokens() + extra > settings.CONTEXT_SUMMARIZE_THRESHOLD:
                    yield phase("summarizing")
                    summary_text, structured_state = await self._summarize_context(ctx, session_dataframes=session_dataframes, tracker=tracker, session_id=session_id)
                    ctx.summarize(summary_text, structured_state=structured_state)
                    yield ev_summary(content=summary_text, structured_state=structured_state)

                logger.info("CTR RESUME | msgs=%d | turn=%d | dfs=%d", len(ctx.messages), session["turn_count"], len(session_dataframes))
                saved_budget = session.get("budget")
                if saved_budget:
                    session_budget = Budget(
                        query_limit=saved_budget.get("query_limit", settings.SESSION_QUERY_LIMIT),
                        row_limit=saved_budget.get("row_limit", settings.SESSION_ROW_LIMIT),
                        time_limit_seconds=saved_budget.get("time_limit", settings.SESSION_TIME_LIMIT_SECONDS),
                    )
                    session_budget.queries_used = saved_budget.get("queries_used", 0)
                    session_budget.rows_fetched = saved_budget.get("rows_fetched", 0)
                    session_budget.time_used = saved_budget.get("time_used", 0.0)
                    # Issue #12: restore the exhaustion flag from the snapshot.
                    session_budget._exhausted = (
                        session_budget.queries_used >= session_budget.query_limit
                        or session_budget.rows_fetched >= session_budget.row_limit
                        or session_budget.time_used >= session_budget.time_limit
                    )
                else:
                    session_budget = Budget(query_limit=settings.SESSION_QUERY_LIMIT, row_limit=settings.SESSION_ROW_LIMIT, time_limit_seconds=settings.SESSION_TIME_LIMIT_SECONDS)
            else:
                ctx, tracker, session_budget = self._fresh_session_state(settings, schema_idx, query, business_context=business_context)
        else:
            ctx, tracker, session_budget = self._fresh_session_state(settings, schema_idx, query, business_context=business_context)

        # Per-turn usage deltas for downstream token accounting: the tracker is
        # session-cumulative on resumed sessions, so the turn's own consumption
        # is final totals minus the restored baseline.
        baseline_total = getattr(tracker, "total_tokens", 0) or 0
        baseline_prompt = getattr(tracker, "total_prompt", 0) or 0
        baseline_completion = getattr(tracker, "total_completion", 0) or 0
        baseline_thinking = getattr(tracker, "total_thinking", 0) or 0
        baseline_cache_hit = getattr(tracker, "total_cache_hit", 0) or 0
        baseline_cache_miss = getattr(tracker, "total_cache_miss", 0) or 0
        baseline_cost = getattr(tracker, "total_cost", 0.0) or 0.0

        # Issue #10: serialize concurrent turns on the same session.
        if turn_lock is not None:
            await turn_lock.acquire()
        try:
            # ctx.memory is injected so SQLTool/PythonTool can auto-capture
            # verified_facts (scalar SQL results + `FACT:` prints).
            exec_max_rows = 10000
            exec_timeout_ms = 15000
            if execution:
                exec_max_rows = max(0, min(int(execution.get("max_rows_per_query", 10000)), 100000))
                exec_timeout_ms = max(1000, min(int(execution.get("max_query_time_ms", 15000)), 60000))
            context = {"executor": turn_executor, "sandbox": self.sandbox, "session_id": session_id, "client_id": client_id, "session_dataframes": session_dataframes, "session_budget": session_budget, "asset_manager": self._asset_manager, "guard": guard, "session_manager": self._session_manager, "memory": ctx.memory, "datasource_id": datasource_id, "schema_dict": schema_dict, "sensitive_tables": sens_tables, "unauthorized_tables": unauthorized, "user_ref": user_ref, "execution_max_rows": exec_max_rows, "execution_timeout_ms": exec_timeout_ms}
            yield status(max_steps=self.max_steps)

            # Delegate the step loop to step_loop.py
            _result: Dict[str, Any] = {}
            try:
                async for _event in _execute_turn(
                    self, ctx, tracker, session_budget, context,
                    session_dataframes, guard, schema_dict, live_budget,
                    _result,
                ):
                    yield _event
            except asyncio.CancelledError:
                raise
            except Exception as e:
                # Persist a failure marker before the error surfaces to the
                # client (main.py emits the SSE error event). The next history
                # load then reports session_error so the chat UI can tell the
                # user the last analysis never completed.
                if self._session_manager is not None:
                    payload = stream_error(e)
                    try:
                        await self._session_manager.record_error(session_id, client_id, {
                            "code": payload["code"],
                            "message": payload["message"],
                            "retryable": payload["retryable"],
                            "query": query,
                        })
                    except Exception as persist_e:
                        logger.warning("Failed to persist session error | session=%s | %s", session_id, persist_e)
                raise

            steps_run = _result.get("steps_run", 0)
            turn_tool_calls = _result.get("turn_tool_calls", [])
            thinking_per_step = _result.get("thinking_per_step", {})
            content_buffer = _result.get("content_buffer", "")
            summary_text = _result.get("summary_text", "")
            answer_structured = _result.get("answer_structured")
            ask_only_turn = _result.get("ask_only_turn", False)
            asked_questions = _result.get("asked_questions")

            if self._session_manager is not None:
                await _enforce_df_budget(session_dataframes, self._session_manager, session_id, client_id=client_id)
            ctx.upsert_available_dataframes(session_dataframes)

            ctx.compact(max_tokens=persist_budget)
            logger.info("CTX PERSISTED (carries into next follow-up) | %s", ctx.log_summary())

            # Per-turn transcript + idempotency key. `turn_uuid` lets Laravel
            # dedupe the completion report (billing + archive) on retries.
            turn_uuid = uuid.uuid4().hex
            turn_answer = content_buffer or summary_text or ""
            turn_payload = {
                "query": query,
                "answer": turn_answer,
                "steps": steps_run,
                "tool_calls": list(turn_tool_calls),
                "thinking_per_step": thinking_per_step,
                "questions": asked_questions,
                "awaiting_question": ask_only_turn,
                "dataframes": {
                    k: {"shape": v.get("shape", [0, 0]), "dtypes": v.get("dtypes", [])}
                    for k, v in session_dataframes.items()
                },
                "answer_structured": answer_structured,
            }

            if self._session_manager is not None:
                increment = not ask_only_turn
                budget_snapshot = {
                    "query_limit": session_budget.query_limit,
                    "row_limit": session_budget.row_limit,
                    "time_limit": session_budget.time_limit,
                    "queries_used": session_budget.queries_used,
                    "rows_fetched": session_budget.rows_fetched,
                    "time_used": session_budget.time_used,
                }
                await self._session_manager.commit_turn(
                    session_id,
                    turn_payload if (turn_tool_calls or content_buffer or summary_text) else {},
                    increment=increment,
                    context=ctx,
                    tracker=tracker,
                    dataframes=context.get("session_dataframes", {}),
                    budget=budget_snapshot,
                    client_id=client_id,
                )
                logger.info("Session saved | turn=%d | msgs=%d | dfs=%d | budget=%s", increment, len(ctx.messages), len(context.get("session_dataframes", {})), session_budget.summary)

            yield done(meta={
                "steps": steps_run, "prompt_tokens": tracker.total_prompt,
                "completion_tokens": tracker.total_completion,
                "reasoning_tokens": tracker.total_thinking, "tokens": tracker.total_tokens,
                "turn_tokens": max(0, tracker.total_tokens - baseline_total),
                "turn_prompt_tokens": max(0, tracker.total_prompt - baseline_prompt),
                "turn_completion_tokens": max(0, tracker.total_completion - baseline_completion),
                "turn_reasoning_tokens": max(0, tracker.total_thinking - baseline_thinking),
                "turn_cache_hit_tokens": max(0, tracker.total_cache_hit - baseline_cache_hit),
                "turn_cache_miss_tokens": max(0, tracker.total_cache_miss - baseline_cache_miss),
                # Billed cost (gateway-reported USD). cost_usd is the session
                # cumulative, turn_cost_usd the delta for this turn; Laravel's
                # ledger records the turn delta as the billing row.
                "cost_usd": tracker.total_cost,
                "turn_cost_usd": max(0.0, tracker.total_cost - baseline_cost),
                "context_used": tracker.step_prompt,
                "context_limit": settings.CONTEXT_LIMIT,
                "awaiting_question": ask_only_turn,
                "answer_structured": answer_structured,
                "turn_uuid": turn_uuid,
                "turn": turn_payload,
            })
            logger.info("ANALYZE DONE | steps=%d | %s", steps_run, tracker.cumulative_summary())
            logger.info("=" * 60)
        finally:
            if turn_lock is not None:
                turn_lock.release()


# Bind extracted step-loop methods onto the Agent class.
Agent._dispatch = _dispatch
Agent._stream_llm = _stream_llm
Agent._execute_turn = _execute_turn
