"""Asset management: static file (plot, CSV) storage with TTL-based orphan
cleanup and path-resolution safety checks. Also the single-owner DataFrame
store (parquet): the on-disk directory is the manifest and source of truth;
RAM is a disposable hot cache with write-through puts."""

import asyncio
import logging
import os
import re
import shutil
import time
from pathlib import Path
from typing import Dict, List, Optional

import pandas as pd

from .interfaces import DataFrameStore, StorageProvider, PeriodicCleanupMixin

logger = logging.getLogger(__name__)

# Client/session ids arrive from URL path segments; strip path-unsafe chars so
# they can be used as directory names. Must stay identical to the sanitizer in
# session_manager.py so both land in the same <root>/<client>/<session>/ dir.
_SAFE = re.compile(r"[^A-Za-z0-9_.-]")


def _safe(name: str) -> str:
    return _SAFE.sub("_", name or "") or ""


_SAFE_DF = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$")


def _checked(name: str) -> str:
    if not _SAFE_DF.match(name):
        raise ValueError(f"Unsafe df_name: {name!r}")
    return name


class AssetManager(PeriodicCleanupMixin, StorageProvider, DataFrameStore):
    def __init__(self, assets_dir: str = "./sessions", ttl_hours: int = 2, base_url: str = ""):
        self._assets_dir = Path(assets_dir)
        # Orphan-only safety net (see _purge_expired). Session lifetime is owned
        # by SessionManager; this TTL reaps plot/CSV files left behind ONLY when a
        # session directory has NO session.json (i.e. crashed/orphaned mid-write).
        # It never deletes assets for a live session, so its scale is a pure
        # orphan grace period and need not match SessionManager's retention.
        self._ttl_seconds = int(ttl_hours) * 3600
        self._base_url = base_url.rstrip("/")
        self._assets_dir.mkdir(parents=True, exist_ok=True)
        self._cleanup_task: Optional[asyncio.Task] = None
        # DataFrame hot cache (disposable — disk is the source of truth).
        self._cache: Dict[str, pd.DataFrame] = {}
        self._locks: Dict[str, asyncio.Lock] = {}
        logger.info("AssetManager initialized | dir=%s | ttl=%ds (orphan-only) | base_url=%s | owns_df_store=yes", self._assets_dir.resolve(), self._ttl_seconds, self._base_url or "(relative)")

    # ── Shared path helpers ────────────────────────────────────────────────

    def _session_dir(self, client_id: str, session_id: str) -> Path:
        return self._assets_dir / _safe(client_id) / _safe(session_id)

    def _df_dir(self, client_id: str, session_id: str) -> Path:
        return self._session_dir(client_id, session_id) / "dfs"

    def _df_path(self, client_id: str, session_id: str, name: str) -> Path:
        return self._df_dir(client_id, session_id) / f"{name}.parquet"

    @staticmethod
    def _cache_key(client_id: str, session_id: str, name: str) -> str:
        return f"{client_id}:{session_id}/{name}"

    def _lock(self, client_id: str, session_id: str) -> asyncio.Lock:
        key = f"{client_id}:{session_id}"
        if key not in self._locks:
            self._locks[key] = asyncio.Lock()
        return self._locks[key]

    def resolve_asset_path(self, client_id: str, session_id: str, subdir: str, filename: str) -> Optional[Path]:
        candidate = (self._assets_dir / client_id / session_id / subdir / filename).resolve()
        # Issue #28: proper ancestor check (string-prefix matching gave false
        # positives for sibling dirs like "assets_evil").
        if not candidate.is_relative_to(self._assets_dir.resolve()):
            return None
        if candidate.exists() and candidate.is_file():
            return candidate
        return None

    # ── DataFrameStore ─────────────────────────────────────────────────────

    async def put(self, client_id: str, session_id: str, name: str, df: pd.DataFrame) -> None:
        _checked(name)
        async with self._lock(client_id, session_id):
            d = self._df_dir(client_id, session_id)
            d.mkdir(parents=True, exist_ok=True)
            path = self._df_path(client_id, session_id, name)
            tmp = Path(f"{path}.tmp")
            df.to_parquet(tmp, compression="zstd", index=False)
            os.replace(str(tmp), str(path))
            self._cache[self._cache_key(client_id, session_id, name)] = df

    async def get(self, client_id: str, session_id: str, name: str) -> Optional[pd.DataFrame]:
        _checked(name)
        k = self._cache_key(client_id, session_id, name)
        if k in self._cache:
            return self._cache[k]
        p = self._df_path(client_id, session_id, name)
        if not p.exists():
            return None
        try:
            df = pd.read_parquet(p)
        except OSError:
            return None
        self._cache[k] = df
        return df

    async def list(self, client_id: str, session_id: str) -> List[str]:
        d = self._df_dir(client_id, session_id)
        if not d.exists():
            return []
        return sorted(p.stem for p in d.glob("*.parquet"))

    async def delete(self, client_id: str, session_id: str, name: str) -> None:
        _checked(name)
        async with self._lock(client_id, session_id):
            self._delete_file(client_id, session_id, name)

    def evict(self, client_id: str, session_id: str, name: str) -> None:
        # Runs post-turn (no concurrent access for this session expected), so it
        # is intentionally lock-free like the old memory-only evict. It is a
        # HARD delete: file + cache go together.
        self._delete_file(client_id, session_id, name)

    def _delete_file(self, client_id: str, session_id: str, name: str) -> None:
        self._cache.pop(self._cache_key(client_id, session_id, name), None)
        try:
            p = self._df_path(client_id, session_id, name)
            if p.exists():
                p.unlink()
        except Exception as e:
            logger.warning("AssetManager: failed to delete df %s/%s/%s | %s", client_id, session_id, name, e)

    async def delete_session(self, client_id: str, session_id: str) -> None:
        # Tears down the WHOLE session directory (session.json, plots, CSVs,
        # dfs) — SessionManager owns session lifetime and calls this on
        # expiry/delete, so all per-session artifacts go together.
        prefix = f"{_safe(client_id)}:{_safe(session_id)}/"
        stale = [k for k in self._cache if k.startswith(prefix)]
        for k in stale:
            del self._cache[k]
        self._locks.pop(f"{client_id}:{session_id}", None)
        d = self._session_dir(client_id, session_id)
        if d.exists():
            shutil.rmtree(d, ignore_errors=True)

    def size_of(self, client_id: str, session_id: str, name: Optional[str] = None) -> int:
        if name is not None:
            try:
                _checked(name)
                p = self._df_path(client_id, session_id, name)
                return p.stat().st_size if p.exists() else 0
            except Exception:
                return 0
        d = self._df_dir(client_id, session_id)
        if not d.exists():
            return 0
        total = 0
        try:
            for p in d.glob("*.parquet"):
                total += p.stat().st_size
        except Exception:
            pass
        return total

    # ── Orphan cleanup ─────────────────────────────────────────────────────

    async def _purge_expired(self):
        # INVARIANT: this method must only ever delete files whose session
        # directory has NO session.json. Live sessions own their assets and are
        # torn down (with their whole directory) by SessionManager, so the
        # session.json check below is what makes this sweep safe to run
        # lock-free. Do NOT extend this to touch live-session files — that is
        # the only reason the per-session lock was removed here.
        now = time.time()
        purged = 0
        for root, dirs, files in os.walk(self._assets_dir):
            for name in files:
                filepath = Path(root) / name
                # Never delete session.json (lifecycle marker owned by SessionManager)
                if name == "session.json":
                    continue
                # Never delete assets for a session that still has live state
                # (session.json present). The session store owns session lifetime
                # and removes the whole session directory on expiry/reset, taking
                # its assets with it. This keeps plot/CSV references valid for the
                # life of the session regardless of the asset TTL.
                session_dir = filepath.parent.parent
                if (session_dir / "session.json").exists():
                    continue
                try:
                    if now - filepath.stat().st_mtime > self._ttl_seconds:
                        filepath.unlink()
                        purged += 1
                except Exception:
                    pass
        if purged:
            logger.info("AssetManager: purged %d expired assets", purged)
