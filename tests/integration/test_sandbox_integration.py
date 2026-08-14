"""Integration tests for the disposable Docker sandbox worker pool.

These require a running Docker daemon and the sandbox image to be built, so
they are kept out of the default unit-test run. Run them explicitly with:

    pytest tests/integration -m integration
"""
import asyncio

import pytest

docker = pytest.importorskip("docker")

from config import Settings
from agent.sandbox import (
    PooledSandbox,
    DockerSandboxPool,
    _parse_mem_limit,
)


def _make_settings(**overrides):
    s = Settings()
    for k, v in overrides.items():
        setattr(s, k, v)
    return s


@pytest.fixture(scope="module")
def docker_env():
    try:
        client = docker.from_env()
        client.ping()
    except Exception as e:  # pragma: no cover - environment dependent
        pytest.skip(f"Docker daemon not available: {e}")
    try:
        client.images.get("code-sandbox:latest")
    except Exception:  # pragma: no cover - environment dependent
        pytest.skip("code-sandbox:latest image not built")
    return client


@pytest.mark.integration
async def test_pool_live_execute(docker_env):
    sb = PooledSandbox(settings=_make_settings(SANDBOX_POOL_MIN=1))
    try:
        await sb.prewarm()
        result = await sb.execute("print('hello')\nx = pd.DataFrame({'a':[1,2]})\nprint(x.shape)", session_id="it-session")
        assert result["status"] == "success"
        assert "hello" in result["output"]
    finally:
        await sb.close()


@pytest.mark.integration
async def test_pool_saturation_serializes_waiters(docker_env):
    # max-live = 1 (override clamps the derived ceiling). With more concurrent
    # executions than the ceiling, excess callers must wait for a freed slot
    # and then spawn a fresh worker -- not crash with QueueEmpty.
    sb = PooledSandbox(settings=_make_settings(SANDBOX_POOL_MIN=1, SANDBOX_POOL_MAX_OVERRIDE=1))
    try:
        await sb.prewarm()

        async def run_all():
            return await asyncio.gather(*[
                sb.execute(f"print('req{i}')", session_id="it-session") for i in range(3)
            ])

        results = await run_all()
        assert all(r["status"] == "success" for r in results)
        assert sum(1 for r in results if "req" in r["output"]) == 3
    finally:
        await sb.close()


@pytest.mark.integration
async def test_emergency_sweep_removes_pool_containers(docker_env):
    # _emergency_sweep is the fallback used when the graceful async shutdown is
    # skipped (reload kills, SIGINT). It must force-remove every container
    # labelled app=sandbox-pool, even if close() never ran.
    sb = PooledSandbox(settings=_make_settings(SANDBOX_POOL_MIN=1))
    try:
        await sb.prewarm()
        live_before = docker_env.containers.list(
            filters={"label": "app=sandbox-pool"}, all=True
        )
        assert len(live_before) >= 1
        # Simulate a shutdown path that bypasses the async close().
        sb._emergency_sweep()
        live_after = docker_env.containers.list(
            filters={"label": "app=sandbox-pool"}, all=True
        )
        assert live_after == []
    finally:
        # Ensure no orphans remain even if the assert above failed mid-way.
        sb._emergency_sweep()


@pytest.mark.integration
async def test_pool_executes_numpy_and_pandas_workloads(docker_env):
    # Regression: sandbox builds where __import__ was stripped from builtins
    # made numpy/pandas workloads crash with NameError: __import__ not defined.
    # The exact classes that killed the forecast turns must execute cleanly.
    sb = PooledSandbox(settings=_make_settings(SANDBOX_POOL_MIN=1))
    code = (
        "import pandas as pd\nimport numpy as np\n"
        "df = pd.DataFrame({'month': ['2026-01','2026-02','2026-03'], 'rev': [1.0, 2.0, 3.0]})\n"
        "arr = np.linalg.inv(np.eye(2))\n"
        "slope, _ = np.polyfit([1, 2, 3], df['rev'].values, 1)\n"
        "buf = df.to_parquet(index=False)\n"
        "print(f'arr={arr.tolist()} slope={slope:.1f} parquet={len(buf)}b')\n"
        "print('FACT: polyfit_slope = 1.0')\n"
    )
    try:
        await sb.prewarm()
        result = await sb.execute(code, session_id="it-session-numpy")
        assert result["status"] == "success", result.get("error")
        assert "slope=1.0" in result["output"], result.get("output")
        assert "FACT: polyfit_slope = 1.0" in result["output"], result.get("output")
    finally:
        await sb.close()
