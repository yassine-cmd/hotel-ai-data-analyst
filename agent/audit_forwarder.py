"""System audit event forwarding to the admin Laravel instance.

Python is the hub: client Laravel instances report events here via
``POST /api/events``, and Python relays them (plus its own) to the admin
instance's daily system-audit log file via the loopback
``/api/internal/events`` endpoint. Events are throttled per (event + client)
and redacted so secrets never reach the log store.

Configured once from ``main.py``'s lifespan. Until then (and when forwarding
is disabled) ``submit_event()`` is a no-op.
"""

import asyncio
import logging
import time
from typing import Optional

import httpx

from agent.errors import redact

logger = logging.getLogger(__name__)


class EventForwarder:
    """Bounded async queue that serializes relayed events to the admin log file."""

    def __init__(self, target_url: Optional[str], throttle_seconds: float = 60.0, max_queue: int = 2000):
        self._target = (target_url or "").rstrip("/")
        self._throttle = max(0.0, throttle_seconds)
        self._queue: asyncio.Queue = asyncio.Queue(maxsize=max_queue)
        self._last: dict[tuple[str, str], float] = {}
        self._task: Optional[asyncio.Task] = None
        self._client: Optional[httpx.AsyncClient] = None

    @property
    def enabled(self) -> bool:
        return bool(self._target)

    def submit(
        self,
        event: str,
        *,
        client_id: Optional[str] = None,
        level: str = "info",
        context: Optional[dict] = None,
        force: bool = False,
    ) -> None:
        """Enqueue an event for relay. Throttled per (event, client_id) unless ``force``."""
        if not self.enabled:
            return
        key = (event, client_id or "")
        now = time.monotonic()
        if not force:
            last = self._last.get(key)
            if last is not None and now - last < self._throttle:
                return
        self._last[key] = now
        payload = {
            "event": event,
            "client_id": client_id,
            "level": level,
            # Redact every value so secrets never reach the admin log file.
            "context": {k: redact(str(v)) for k, v in (context or {}).items()},
        }
        try:
            self._queue.put_nowait(payload)
        except asyncio.QueueFull:
            logger.warning("Event forward queue full; dropping event=%s", event)

    async def start(self) -> None:
        if self.enabled and self._task is None:
            self._client = httpx.AsyncClient(timeout=5.0)
            self._task = asyncio.create_task(self._run())

    async def stop(self) -> None:
        if self._task is not None:
            self._task.cancel()
            try:
                await self._task
            except asyncio.CancelledError:
                pass
            self._task = None
        if self._client is not None:
            await self._client.aclose()
            self._client = None

    async def _run(self) -> None:
        try:
            while True:
                item = await self._queue.get()
                await self._send(item)
        except asyncio.CancelledError:
            while not self._queue.empty():
                try:
                    await self._send(self._queue.get_nowait())
                except asyncio.QueueEmpty:
                    break
                except Exception:
                    break
        except Exception:
            logger.exception("Event forwarder crashed")

    async def _send(self, item: dict) -> None:
        if self._client is None or not self.enabled:
            return
        try:
            resp = await self._client.post(f"{self._target}/api/internal/events", json=item)
            resp.raise_for_status()
        except Exception as e:
            logger.warning("Event forward failed | event=%s | error=%s", item["event"], e)


# Process-wide singleton. Configured from main.py lifespan; inert until then.
_event_forwarder = EventForwarder(None)


def configure_forwarder(target_url: Optional[str], throttle_seconds: float = 60.0) -> EventForwarder:
    """Replace the process-wide forwarder (called once from the lifespan)."""
    global _event_forwarder
    _event_forwarder = EventForwarder(target_url, throttle_seconds)
    return _event_forwarder


def submit_event(
    event: str,
    *,
    client_id: Optional[str] = None,
    level: str = "info",
    context: Optional[dict] = None,
    force: bool = False,
) -> None:
    """Convenience wrapper around the process-wide forwarder."""
    _event_forwarder.submit(event, client_id=client_id, level=level, context=context, force=force)


def get_forwarder() -> EventForwarder:
    """Expose the process-wide forwarder for lifespan start/stop."""
    return _event_forwarder
