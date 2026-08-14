"""Tests for the disposable Docker sandbox worker pool.

Pure-logic tests run anywhere. The live integration tests are skipped unless a
Docker daemon is reachable and the sandbox image is built — they live in
tests/integration/test_sandbox_integration.py.
"""
import pytest

from config import Settings
from agent.sandbox import (
    DockerSandboxPool,
    PooledSandbox,
    _parse_mem_limit,
)


def test_parse_mem_limit():
    assert _parse_mem_limit("512m") == pytest.approx(0.5)
    assert _parse_mem_limit("1g") == pytest.approx(1.0)
    assert _parse_mem_limit("256k") == pytest.approx(256 / 1024 / 1024)
    assert _parse_mem_limit("1073741824") == pytest.approx(1.0)


def _make_settings(**overrides):
    s = Settings()
    for k, v in overrides.items():
        setattr(s, k, v)
    return s


class _FakeClient:
    def __init__(self, mem_gib=4, ncpu=4):
        self._info = {"MemTotal": int(mem_gib * 1024 ** 3), "NCPU": ncpu}

    def info(self):
        return self._info

    def ping(self):
        return True


def test_derive_max():
    s = _make_settings(
        SANDBOX_HOST_HEADROOM_GIB=1.0,
        SANDBOX_MEM_LIMIT="512m",
        SANDBOX_CPUS=1.0,
        SANDBOX_POOL_MIN=2,
    )
    pool = DockerSandboxPool(_FakeClient(mem_gib=4, ncpu=4), s)
    # mem: (4-1)/0.5 = 6 ; cpu: 4/1 = 4 -> min = 4
    assert pool._derive_max() == 4


def test_derive_max_clamps_to_min():
    s = _make_settings(
        SANDBOX_HOST_HEADROOM_GIB=3.9,
        SANDBOX_MEM_LIMIT="512m",
        SANDBOX_CPUS=1.0,
        SANDBOX_POOL_MIN=2,
    )
    pool = DockerSandboxPool(_FakeClient(mem_gib=4, ncpu=1), s)
    # mem: (4-3.9)/0.5 = 0 ; cpu: 1/1 = 1 -> min clamps to 2
    assert pool._derive_max() == 2


def test_derive_max_override_clamped():
    s = _make_settings(
        SANDBOX_HOST_HEADROOM_GIB=1.0,
        SANDBOX_MEM_LIMIT="512m",
        SANDBOX_CPUS=1.0,
        SANDBOX_POOL_MIN=2,
        SANDBOX_POOL_MAX_OVERRIDE=3,
    )
    pool = DockerSandboxPool(_FakeClient(mem_gib=4, ncpu=4), s)
    # derived 4, override 3 -> clamped to 3
    assert pool._derive_max() == 3


def _autobuild_fake_client(missing=True, flip=False):
    from docker.errors import ImageNotFound

    class _Images:
        def __init__(self):
            self.get_calls = 0

        def get(self, tag):
            self.get_calls += 1
            if missing and (not flip or self.get_calls == 1):
                raise ImageNotFound(tag)
            return object()

    class _Api:
        def __init__(self):
            self.build_calls = []

        def build(self, **kwargs):
            self.build_calls.append(kwargs)

            def gen():
                yield {"stream": "Step 1/5 : FROM python:3.11-slim\n"}

            return gen()

    class _Client:
        def __init__(self):
            self.images = _Images()
            self.api = _Api()

    return _Client()


async def test_check_image_skips_build_when_disabled():
    s = _make_settings(SANDBOX_SKIP_BUILD=True)
    pool = DockerSandboxPool(_autobuild_fake_client(missing=True), s)
    with pytest.raises(RuntimeError):
        await pool._check_image()
    # With SKIP_BUILD set, the image must NOT be auto-built.
    assert pool._client.api.build_calls == []


async def test_check_image_autobuilds_when_missing():
    s = _make_settings(SANDBOX_SKIP_BUILD=False)
    pool = DockerSandboxPool(_autobuild_fake_client(missing=True, flip=True), s)
    await pool._check_image()
    assert len(pool._client.api.build_calls) == 1
    assert pool._client.api.build_calls[0]["tag"] == "code-sandbox:latest"
    assert pool._client.api.build_calls[0]["dockerfile"] == "Dockerfile"


def test_pooled_sandbox_init_sets_defaults():
    """PooledSandbox.__init__ sets _connected=False and creates a pool."""
    sb = PooledSandbox()
    assert sb._connected is False
    assert sb._pool is not None
    assert isinstance(sb._pool, DockerSandboxPool)


async def test_pooled_execute_acquires_and_releases():
    class _FakeWorker:
        def __init__(self):
            self.calls = []

        async def execute(self, code, session_id="default", dataframes=None):
            self.calls.append(code)
            return {"status": "success", "data": {}}

        async def destroy(self):
            pass

    class _FakePool:
        def __init__(self):
            self.acquired = 0
            self.released = 0
            self.worker = _FakeWorker()

        async def acquire(self):
            self.acquired += 1
            return self.worker

        async def release_destroyed(self, w):
            self.released += 1

    sb = PooledSandbox.__new__(PooledSandbox)
    sb._pool = _FakePool()
    sb._connected = True
    sb._settings = Settings()

    result = await sb.execute("x = 1", session_id="test_sess")
    assert result["status"] == "success"
    assert sb._pool.acquired == 1
    assert sb._pool.released == 1
    assert sb._pool.worker.calls == ["x = 1"]


async def test_pooled_execute_not_connected():
    sb = PooledSandbox.__new__(PooledSandbox)
    sb._pool = None
    sb._connected = False
    sb._settings = Settings()
    with pytest.raises(RuntimeError):
        await sb.execute("x = 1", session_id="test")


async def test_pool_shutdown():
    class _FakeWorker:
        async def destroy(self):
            pass

    class _FakePool2:
        def __init__(self):
            self.swept = False

        async def acquire(self):
            return _FakeWorker()

        async def release_destroyed(self, w):
            pass

        async def shutdown(self):
            self.swept = True

    pool = _FakePool2()
    sb = PooledSandbox.__new__(PooledSandbox)
    sb._pool = pool
    sb._connected = True
    sb._settings = Settings()
    await sb.close()
    assert pool.swept
    # close() delegates to pool.shutdown(); pool reference is kept
    assert sb._pool is pool
