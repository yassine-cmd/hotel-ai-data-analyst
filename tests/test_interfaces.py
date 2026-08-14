import asyncio
import pytest

from agent.interfaces import PeriodicCleanupMixin


class _ConcreteCleanup(PeriodicCleanupMixin):
    def __init__(self):
        self._cleanup_task = None
        self.purge_calls = 0

    async def _purge_expired(self):
        self.purge_calls += 1


async def test_cleanup_start_and_shutdown():
    obj = _ConcreteCleanup()

    obj.start_cleanup(interval_seconds=3600)
    assert obj._cleanup_task is not None
    await obj.shutdown()
    assert obj._cleanup_task is None


async def test_cleanup_double_start_raises():
    obj = _ConcreteCleanup()

    obj.start_cleanup(interval_seconds=3600)
    with pytest.raises(RuntimeError, match="already started"):
        obj.start_cleanup(interval_seconds=3600)
    await obj.shutdown()


async def test_cleanup_purge_eventually_runs():
    obj = _ConcreteCleanup()
    assert obj.purge_calls == 0
    obj.start_cleanup(interval_seconds=0.01)
    count = None
    for _ in range(50):
        if obj.purge_calls > 0:
            count = obj.purge_calls
            break
        await asyncio.sleep(0.01)
    await obj.shutdown()
    assert count is not None and count > 0, "background cleanup task never called _purge_expired"


async def test_cleanup_not_implemented_raises():
    class _NoPurge(PeriodicCleanupMixin):
        def __init__(self):
            self._cleanup_task = None

    obj = _NoPurge()
    with pytest.raises(NotImplementedError):
        await obj._purge_expired()
