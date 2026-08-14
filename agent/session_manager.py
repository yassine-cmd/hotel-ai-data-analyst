"""Session persistence: per-client/session state, turn history, and cleanup.
Idle working state is evicted from memory (lossless — lazily reloaded from
disk); sessions are only deleted from disk after the retention window (the sole
destructive TTL). DataFrame persistence is delegated to the injected
DataFrameStore (AssetManager owns it)."""

import asyncio
import json
import logging
import os
import re
import uuid
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, Optional

from .interfaces import ClientStore, DataFrameStore, PeriodicCleanupMixin

logger = logging.getLogger(__name__)

# Client/session ids arrive from URL path segments; strip path-unsafe chars so
# they can be used as directory names (the in-RAM key uses ':' which is illegal
# on Windows, so it must never be the on-disk directory name).
_SAFE = re.compile(r"[^A-Za-z0-9_.-]")


def _safe(name: str) -> str:
    return _SAFE.sub("_", name or "") or ""


def _parse_dt(value):
    if not value:
        return None
    if isinstance(value, datetime):
        return value
    try:
        return datetime.fromisoformat(value)
    except Exception:
        return None


def _serialize_context(ctx):
    try:
        if ctx is None:
            return None
        messages = getattr(ctx, "messages", None)
        if messages is None:
            return None
        return {
            "messages": messages,
            "memory": {
                "code_log": ctx.tool_trail,
                "referenced_tables": ctx.tables_touched,
                "recent_errors": ctx.recent_errors,
                "verified_facts": ctx.verified_facts,
                "user_intents": ctx.user_intents,
                "constraints": ctx.constraints,
            },
            "reference_data_msg": getattr(ctx, "reference_data_msg", None),
            "available_dataframes_msg": getattr(ctx, "available_dataframes_msg", None),
            "calibrator": getattr(getattr(ctx, "calibrator", None), "snapshot", lambda: None)(),
        }
    except Exception as e:
        logger.warning("SessionManager: context serialization failed | %s", e)
        return None


def _deserialize_context(data):
    if not isinstance(data, dict) or "messages" not in data:
        return None
    try:
        from .context import Context
        ctx = Context("", "")
        # Strip any legacy [SESSION STATE] user message baked into old session
        # files — that content is now rebuilt transiently at request time.
        cleaned = [
            m for m in (data.get("messages", []) or [])
            if not (m.get("role") == "user" and "[SESSION STATE]" in str(m.get("content", "")))
        ]
        ctx.messages = cleaned
        mem = data.get("memory")
        if isinstance(mem, dict):
            # Migrate old ContextMemory keys to new Context field names
            ctx.tool_trail = mem.get("code_log", [])
            ctx.tables_touched = mem.get("referenced_tables", [])
            ctx.recent_errors = mem.get("recent_errors", [])
            ctx.verified_facts = mem.get("verified_facts", {})
            ctx.user_intents = mem.get("user_intents", [])
            ctx.constraints = mem.get("constraints", [])
        ctx.reference_data_msg = data.get("reference_data_msg")
        ctx.available_dataframes_msg = data.get("available_dataframes_msg")
        calibrator = getattr(ctx, "calibrator", None)
        if calibrator is not None:
            calibrator.restore(data.get("calibrator"))
        return ctx
    except Exception as e:
        logger.warning("SessionManager: context deserialization failed | %s", e)
        return None


def _serialize_tracker(tracker):
    try:
        return dict(vars(tracker)) if tracker is not None else None
    except Exception:
        return None


def _tracker_context_window(tracker):
    if tracker is None:
        return None
    if isinstance(tracker, dict):
        return {
            k: tracker[k]
            for k in ("total_prompt", "total_completion", "total_thinking", "total_tokens")
            if k in tracker and tracker[k] is not None
        } or None
    obj = tracker
    result = {}
    for attr in ("total_prompt", "total_completion", "total_thinking", "total_tokens"):
        try:
            v = getattr(obj, attr, None)
            if v is not None:
                result[attr] = v
        except Exception:
            pass
    return result or None


class SessionManager(PeriodicCleanupMixin, ClientStore):
    def __init__(self, base_dir: str = "./sessions", ttl_minutes: int = 30, max_turns: int = 10,
                 retention_days: int = 90, dataframe_store: Optional[DataFrameStore] = None):
        # Session state lives under the SAME root as AssetManager, so every
        # per-session artifact (plots, CSVs, session.json, dataframe parquet)
        # lives in one directory: <base_dir>/<client_id>/<session_id>/.
        self._base_dir = Path(base_dir)
        self._base_dir.mkdir(parents=True, exist_ok=True)
        # Two entirely different lifetimes (see _purge_expired):
        #  * _memory_idle — how long a loaded session's working state stays
        #    resident in RAM. Eviction here is memory-only: history/context/dfs
        #    remain on disk and are lazily reloaded by get_or_create / history.
        #  * _retention — how long a session survives on disk after its last
        #    activity. This is the ONLY destructive TTL and should be set to a
        #    business-safe value (defaults to 90 days).
        self._memory_idle = timedelta(minutes=ttl_minutes)
        self._retention = timedelta(days=retention_days)
        self._max_turns = max_turns
        self._sessions: Dict[str, dict] = {}
        self._cleanup_task: Optional[asyncio.Task] = None
        # DataFrame persistence is owned by AssetManager (single store). In
        # production main.py injects the same AssetManager instance that also
        # serves plot/CSV assets; the fallback keeps SessionManager usable
        # standalone (tests, embedding) without splitting ownership.
        if dataframe_store is None:
            from .asset_manager import AssetManager
            dataframe_store = AssetManager(assets_dir=self._base_dir)
        self._dfs = dataframe_store

    @property
    def store(self) -> DataFrameStore:
        return self._dfs

    @staticmethod
    def _key(session_id: str, client_id: str) -> str:
        return f"{client_id}:{session_id}"

    def _session_dir(self, client_id: str, session_id: str) -> Path:
        d = self._base_dir / _safe(client_id) / _safe(session_id)
        d.mkdir(parents=True, exist_ok=True)
        return d

    def _state_path(self, client_id: str, session_id: str) -> Path:
        return self._session_dir(client_id, session_id) / "session.json"

    def _new_entry(self, name: str = "") -> dict:
        return {
            "name": name,
            "context": None,
            "tracker": None,
            "dataframes": {},
            "budget": None,
            "turn_count": 0,
            "history": [],
            "error": None,
            "created_at": datetime.now(),
            "last_access": datetime.now(),
            "lock": asyncio.Lock(),
        }

    def _serialize_entry(self, entry: dict) -> dict:
        created = _parse_dt(entry.get("created_at")) or datetime.now()
        return {
            "name": entry.get("name", ""),
            "context": _serialize_context(entry.get("context")),
            "tracker": _serialize_tracker(entry.get("tracker")),
            "dataframes": entry.get("dataframes", {}),
            "budget": entry.get("budget"),
            "turn_count": entry.get("turn_count", 0),
            "history": entry.get("history", []),
            "error": entry.get("error"),
            "created_at": created.isoformat(),
            "last_access": datetime.now().isoformat(),
        }

    def _persist(self, key: str, entry: dict) -> None:
        try:
            client_id, session_id = key.split(":", 1)
            state_path = self._state_path(client_id, session_id)
            serial = self._serialize_entry(entry)
            tmp = state_path.with_suffix(f".json.{os.getpid()}.{uuid.uuid4().hex}.tmp")
            tmp.write_text(json.dumps(serial, default=str), encoding="utf-8")
            tmp.replace(state_path)
        except Exception as e:
            try:
                tmp.unlink(missing_ok=True)
            except Exception:
                pass
            logger.warning("SessionManager: persist failed | key=%s | %s", key, e)

    @staticmethod
    def cleanup_stale_tmp_files(session_dir: str) -> None:
        base = Path(session_dir)
        if not base.exists():
            return
        for tmp_path in base.rglob("*.json.*.tmp"):
            try:
                tmp_path.unlink()
            except Exception:
                pass

    def _load_from_disk(self, client_id: str, session_id: str) -> Optional[dict]:
        state_path = self._state_path(client_id, session_id)
        if not state_path.exists():
            return None
        try:
            data = json.loads(state_path.read_text(encoding="utf-8"))
        except Exception as e:
            logger.warning("SessionManager: failed to read %s | %s", state_path, e)
            return None
        entry = self._new_entry(data.get("name", ""))
        entry["context"] = _deserialize_context(data.get("context"))
        entry["tracker"] = data.get("tracker")
        entry["dataframes"] = data.get("dataframes", {}) or {}
        entry["budget"] = data.get("budget")
        entry["turn_count"] = data.get("turn_count", 0) or 0
        entry["history"] = data.get("history", []) or []
        entry["error"] = data.get("error")
        entry["created_at"] = _parse_dt(data.get("created_at")) or datetime.now()
        entry["last_access"] = _parse_dt(data.get("last_access")) or datetime.now()
        return entry

    async def get_or_create(self, session_id: str, client_id: str, name: str = "") -> dict:
        # One-time migration: old dataframes/ → dfs/
        sess_dir = self._session_dir(client_id, session_id)
        old_dfs = sess_dir / "dataframes"
        new_dfs = sess_dir / "dfs"
        if old_dfs.exists() and not new_dfs.exists():
            try:
                os.replace(str(old_dfs), str(new_dfs))
                logger.info("SessionManager: migrated dataframes/ → dfs/ | client=%s session=%s", client_id, session_id)
            except Exception as e:
                logger.warning("SessionManager: dataframes→dfs migration failed | %s", e)

        key = self._key(session_id, client_id)
        if key not in self._sessions:
            entry = self._load_from_disk(client_id, session_id)
            if entry is None:
                entry = self._new_entry(name)
                logger.info("SessionManager: created session | key=%s", key)
            else:
                logger.info("SessionManager: loaded session from disk | key=%s", key)
            self._sessions[key] = entry
        elif name:
            self._sessions[key]["name"] = name
        self._sessions[key]["last_access"] = datetime.now()
        return self._sessions[key]

    async def list_sessions(self, client_id: str, include_disk: bool = True) -> list:
        key_prefix = f"{client_id}:"
        sessions = {}
        for k, v in self._sessions.items():
            if k.startswith(key_prefix):
                sid = k[len(key_prefix):]
                sessions[sid] = {
                    "session_id": sid,
                    "name": v.get("name", ""),
                    "turn_count": v.get("turn_count", 0),
                    "context_window": _tracker_context_window(v.get("tracker")),
                    "path": f"{client_id}/{sid}/",
                    "created_at": v.get("created_at").isoformat() if isinstance(v.get("created_at"), datetime) else v.get("created_at"),
                    "last_access": v.get("last_access").isoformat() if isinstance(v.get("last_access"), datetime) else v.get("last_access"),
                }
        if include_disk:
            client_dir = self._base_dir / _safe(client_id)
            if client_dir.exists():
                for d in client_dir.iterdir():
                    if d.is_dir() and (d / "session.json").exists():
                        sid = d.name
                        if sid not in sessions:
                            try:
                                data = json.loads((d / "session.json").read_text(encoding="utf-8"))
                                sessions[sid] = {
                                    "session_id": sid,
                                    "name": data.get("name", ""),
                                    "turn_count": data.get("turn_count", 0),
                                    "context_window": _tracker_context_window(data.get("tracker")),
                                    "path": f"{client_id}/{sid}/",
                                    "created_at": data.get("created_at", ""),
                                    "last_access": data.get("last_access", ""),
                                }
                            except Exception:
                                pass
        result = list(sessions.values())
        result.sort(key=lambda s: s.get("created_at") or "", reverse=True)
        return result

    async def commit_turn(self, session_id: str, turn_data: dict, *, increment: bool = False, context: Any = None, tracker: Any = None, client_id: str, dataframes: Optional[dict] = None, budget: Optional[dict] = None) -> int:
        """Apply all end-of-turn updates (history append, optional turn-count
        increment, context/tracker/dataframe/budget snapshot) and persist the
        session entry exactly once.

        Replaces the previous record_turn() + increment_turn() + save() sequence,
        each of which independently serialized and fsync'd the entire entry — three
        full disk writes per turn for what is a single logical "turn ended" event.
        """
        key = self._key(session_id, client_id)
        entry = self._sessions.get(key)
        if entry is None:
            return 0
        if turn_data is not None:
            entry.setdefault("history", []).append(turn_data)
            if len(entry["history"]) > self.MAX_HISTORY_TURNS:
                dropped = len(entry["history"]) - self.MAX_HISTORY_TURNS
                entry["history"] = entry["history"][-self.MAX_HISTORY_TURNS:]
                logger.debug("SessionManager: dropped %d old history turns | key=%s", dropped, key)
        if increment:
            entry["turn_count"] += 1
        entry["context"] = context
        entry["tracker"] = tracker
        entry["error"] = None  # a completed turn clears any prior failure marker
        if dataframes is not None:
            entry["dataframes"] = dataframes
        if budget is not None:
            entry["budget"] = budget
        entry["last_access"] = datetime.now()
        self._persist(key, entry)
        return entry["turn_count"]

    async def record_error(self, session_id: str, client_id: str, error: dict) -> None:
        """Persist a structured {code, message, retryable, query} failure marker
        so a session whose turn crashed survives the process and is surfaced to
        the client on the next history load. A later successful commit_turn
        clears it."""
        key = self._key(session_id, client_id)
        entry = self._sessions.get(key)
        if entry is None:
            entry = self._load_from_disk(client_id, session_id)
            if entry is None:
                return
            self._sessions[key] = entry
        entry["error"] = error
        entry["last_access"] = datetime.now()
        self._persist(key, entry)

    async def get_session_error(self, session_id: str, client_id: str):
        key = self._key(session_id, client_id)
        entry = self._sessions.get(key)
        if entry is None:
            entry = self._load_from_disk(client_id, session_id)
            if entry is not None:
                self._sessions[key] = entry
        return entry.get("error") if entry else None

    MAX_HISTORY_TURNS = 50

    async def get_history(self, session_id: str, client_id: str) -> list:
        key = self._key(session_id, client_id)
        entry = self._sessions.get(key)
        if entry is None:
            entry = self._load_from_disk(client_id, session_id)
            if entry is not None:
                self._sessions[key] = entry
        return list(entry.get("history", [])) if entry else []

    async def rename(self, session_id: str, client_id: str, new_name: str) -> Optional[dict]:
        key = self._key(session_id, client_id)
        entry = self._sessions.get(key)
        if entry is None:
            entry = self._load_from_disk(client_id, session_id)
            if entry is None:
                return None
            self._sessions[key] = entry
        entry["name"] = new_name
        entry["last_access"] = datetime.now()
        self._persist(key, entry)
        return entry

    async def get_turn_count(self, session_id: str, client_id: str) -> int:
        key = self._key(session_id, client_id)
        entry = self._sessions.get(key)
        return entry["turn_count"] if entry else 0

    async def delete(self, session_id: str, client_id: str) -> None:
        key = self._key(session_id, client_id)
        self._sessions.pop(key, None)
        await self._dfs.delete_session(client_id, session_id)
        logger.info("SessionManager: delete | key=%s", key)

    async def _purge_expired(self):
        # Two independent lifetimes, one sweep:
        #
        # 1. MEMORY eviction (lossless). Drop loaded working state for sessions
        #    idle past _memory_idle. Everything was already persisted at commit
        #    time, so no data is lost — get_or_create/history lazily reload
        #    from disk. Never evict a session whose turn is in flight (lock held).
        # 2. DISK retention (destructive) — this is the ONLY place session data
        #    is deleted. A session directory is removed only when its last
        #    activity exceeds _retention, or when it is already an orphan on
        #    disk and past the retention window.
        now = datetime.now()

        # — 1. Memory-only eviction of idle loaded state.
        evicted = [
            k for k, v in self._sessions.items()
            if now - v["last_access"] > self._memory_idle and not v["lock"].locked()
        ]
        for k in evicted:
            del self._sessions[k]

        # — 2. Destructive disk retention sweep.
        #     Sessions still live in memory (recently touched) are always kept.
        active_dirs = set()
        for k in self._sessions:
            cid, sid = k.split(":", 1)
            active_dirs.add((_safe(cid), _safe(sid)))
        try:
            if self._base_dir.exists():
                for client_dir in self._base_dir.iterdir():
                    if not client_dir.is_dir():
                        continue
                    for sess_dir in client_dir.iterdir():
                        if not sess_dir.is_dir():
                            continue
                        if (client_dir.name, sess_dir.name) in active_dirs:
                            continue
                        sp = sess_dir / "session.json"
                        if not sp.exists():
                            continue
                        try:
                            data = json.loads(sp.read_text(encoding="utf-8"))
                            la = _parse_dt(data.get("last_access"))
                            if la and now - la > self._retention and sess_dir.resolve().is_relative_to(self._base_dir.resolve()):
                                cid_safe, sid_safe = _safe(sess_dir.parent.name), _safe(sess_dir.name)
                                await self._dfs.delete_session(cid_safe, sid_safe)
                        except Exception:
                            pass
        except Exception as e:
            logger.warning("SessionManager: purge disk error | %s", e)
        if evicted:
            logger.info("SessionManager: evicted %d idle sessions from memory | retention keeps disks", len(evicted))
