"""Abstract interfaces for dependency injection: ClientStore, SandboxClient,
StorageProvider, DataFrameStore, and the PeriodicCleanupMixin."""

from __future__ import annotations

import asyncio
import logging
from abc import ABC, abstractmethod
from pathlib import Path
from typing import TYPE_CHECKING, Any, Dict, List, Optional

if TYPE_CHECKING:
    import pandas as pd

logger = logging.getLogger(__name__)


class PeriodicCleanupMixin:
    """Share the duplicated start_cleanup / _cleanup_loop / shutdown boilerplate
    between SessionManager and AssetManager. Subclasses implement _purge_expired.
    """

    def start_cleanup(self, interval_seconds: int = 300):
        if getattr(self, "_cleanup_task", None) is not None:
            raise RuntimeError(f"{type(self).__name__}: cleanup already started")
        self._cleanup_task = asyncio.create_task(self._cleanup_loop(interval_seconds))
        logger.info("%s: cleanup started | interval=%ds", type(self).__name__, interval_seconds)

    async def _cleanup_loop(self, interval: int):
        try:
            while True:
                await asyncio.sleep(interval)
                try:
                    await self._purge_expired()
                except Exception:
                    logger.exception("%s: purge error in cleanup loop", type(self).__name__)
        except asyncio.CancelledError:
            pass

    async def shutdown(self):
        if self._cleanup_task:
            self._cleanup_task.cancel()
            try:
                await self._cleanup_task
            except asyncio.CancelledError:
                pass
            self._cleanup_task = None

    async def _purge_expired(self):
        # Subclasses implement orphan cleanup. IMPORTANT: this must only ever
        # delete files belonging to sessions that no longer have live state
        # (e.g. a session.json marker). It runs lock-free on the assumption that
        # live-session files are never touched here — keep that invariant or the
        # lock-free sweep can race with active reads/writes.
        raise NotImplementedError


class ClientStore(ABC):
    @abstractmethod
    async def get_or_create(self, session_id: str, client_id: str) -> Dict[str, Any]:
        pass

    @abstractmethod
    async def delete(self, session_id: str, client_id: str) -> None:
        pass

    @abstractmethod
    async def get_turn_count(self, session_id: str, client_id: str) -> int:
        pass

    @abstractmethod
    async def commit_turn(
        self,
        session_id: str,
        turn_data: dict,
        *,
        increment: bool = False,
        context: Any = None,
        tracker: Any = None,
        client_id: str,
        dataframes: Optional[Dict] = None,
        budget: Optional[Dict] = None,
    ) -> int:
        """Apply all end-of-turn updates and persist the session exactly once."""

    @abstractmethod
    async def get_history(self, session_id: str, client_id: str) -> list:
        """Return the persisted list of turn dicts for UI restore."""

class SandboxClient(ABC):
    @abstractmethod
    async def execute(
        self,
        code: str,
        session_id: str,
        dataframes: Optional[Dict[str, bytes]] = None,
    ) -> Dict[str, Any]:
        pass

    @abstractmethod
    async def close(self) -> None:
        pass


class StorageProvider(ABC):
    @abstractmethod
    def resolve_asset_path(self, client_id: str, session_id: str, subdir: str, filename: str) -> Optional[Path]:
        pass


class DataFrameStore(ABC):
    """Pluggable DataFrame persistence. The on-disk directory is the single
    source of truth (manifest); RAM is a disposable hot cache. Eviction is a
    HARD delete: RAM cache, disk file, and metadata go together so that what
    the agent sees, what is on disk, and what is in memory stay the same set,
    bounded by one size budget."""

    @abstractmethod
    async def put(self, client_id: str, session_id: str, name: str, df: "pd.DataFrame") -> None:
        pass

    @abstractmethod
    async def get(self, client_id: str, session_id: str, name: str) -> Optional["pd.DataFrame"]:
        pass

    @abstractmethod
    async def list(self, client_id: str, session_id: str) -> List[str]:
        pass

    @abstractmethod
    async def delete(self, client_id: str, session_id: str, name: str) -> None:
        """Physically delete a single DataFrame (file + cache)."""

    @abstractmethod
    async def delete_session(self, client_id: str, session_id: str) -> None:
        """Physically delete the whole session directory (dfs + assets + metadata)."""

    @abstractmethod
    def evict(self, client_id: str, session_id: str, name: str) -> None:
        """Physically evict one DataFrame (file + cache). Never memory-only:
        disk and memory are the same set."""

    @abstractmethod
    def size_of(self, client_id: str, session_id: str, name: Optional[str] = None) -> int:
        """On-disk bytes for one DataFrame, or the session total when name is None."""
