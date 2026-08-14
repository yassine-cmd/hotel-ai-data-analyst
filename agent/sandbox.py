"""Sandbox for isolated Python code execution via disposable Docker workers
with a connection-pooling strategy."""

from typing import Dict, Any, Optional
import asyncio
import base64
import logging
import os
import secrets
import time
from urllib.parse import urlparse

import httpx
import docker
from docker.errors import ImageNotFound
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type
from config import get_settings
from .interfaces import SandboxClient


logger = logging.getLogger(__name__)


_RETRYABLE_CONNECT = (httpx.ConnectError, httpx.PoolTimeout)


def _retryable_connect(func):
    """Retry on connection-level errors only — safe for stateful operations
    where ReadTimeout/WriteTimeout could mean the server already executed."""
    return retry(
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=0.5, min=0.5, max=4),
        retry=retry_if_exception_type(_RETRYABLE_CONNECT),
    )(func)


def _parse_mem_limit(value: str) -> float:
    """Parse a Docker memory limit string into GiB (float)."""
    s = value.strip().lower()
    if s.endswith("g"):
        return float(s[:-1])
    if s.endswith("m"):
        return float(s[:-1]) / 1024.0
    if s.endswith("k"):
        return float(s[:-1]) / (1024.0 * 1024.0)
    return float(s) / (1024.0 ** 3)


class DockerSandboxWorker:
    """One ephemeral container. Used once, then destroyed."""

    def __init__(self, container, base_url: str, timeout: int):
        self._container = container
        self._base_url = base_url.rstrip("/")
        self._timeout = timeout
        self._client = httpx.AsyncClient(timeout=self._timeout)
        self._id = container.id[:12]

    @_retryable_connect
    async def execute(
        self,
        code: str,
        session_id: str,
        dataframes: Optional[Dict[str, bytes]] = None,
    ) -> Dict[str, Any]:
        payload: Dict[str, Any] = {"code": code, "session_id": session_id}
        if dataframes:
            payload["dataframes"] = {
                name: base64.b64encode(parquet_bytes).decode("utf-8")
                for name, parquet_bytes in dataframes.items()
            }
        logger.info("Worker %s | code_length=%d | dataframes=%d", self._id, len(code), len(dataframes or {}))
        start = time.perf_counter()
        response = await self._client.post(f"{self._base_url}/execute", json=payload)
        elapsed_ms = (time.perf_counter() - start) * 1000
        response.raise_for_status()
        result = response.json()
        logger.info("Worker %s | status=%s | elapsed_ms=%.1f | returned_dfs=%d",
                    self._id, result.get('status'), elapsed_ms, len(result.get('data', {})))
        if result.get('error'):
            logger.warning("Worker %s error: %s", self._id, result['error'][:200])
        return result

    async def destroy(self) -> None:
        try:
            await asyncio.to_thread(self._container.remove, force=True)
        except Exception as e:
            logger.warning("Worker %s destroy failed: %s", self._id, e)
        try:
            await self._client.aclose()
        except Exception:
            pass


class DockerSandboxPool:
    """Owns the Docker client and the lifecycle of disposable workers.

    Keeps SANDBOX_POOL_MIN warm containers ready; scales up to a
    resource-derived ceiling on burst; destroys each worker after a single
    use and replenishes the warm floor in the background.
    """

    def __init__(self, client, settings):
        self._client = client  # docker.DockerClient (set in connect())
        self._settings = settings
        self._ready = None  # created in connect() (needs a running event loop)
        self._cond_obj = None  # created in connect() (needs a running event loop)
        self._live = 0
        self._max_live = 0
        self._stop = False
        self._bg_tasks: set = set()

    @property
    def _cond(self) -> asyncio.Condition:
        if self._cond_obj is None:
            raise RuntimeError("Pool not connected; call prewarm() before acquire()")
        return self._cond_obj

    def _ensure_async(self) -> None:
        if self._ready is None:
            raise RuntimeError("Pool not connected; call prewarm() before acquire()")

    async def connect(self) -> None:
        await asyncio.to_thread(self._client.ping)
        await self._check_image()
        self._max_live = await asyncio.to_thread(self._derive_max)
        self._ready = asyncio.Queue()
        self._cond_obj = asyncio.Condition()
        logger.info(
            "Sandbox pool connected | image=%s | max_live=%d | min=%d",
            self._settings.SANDBOX_IMAGE, self._max_live, self._settings.SANDBOX_POOL_MIN,
        )

    def _derive_max(self) -> int:
        info = self._client.info()
        mem_total_bytes = info.get("MemTotal", 0)
        ncpu = info.get("NCPU", 1)
        mem_gib = mem_total_bytes / (1024 ** 3)
        available_gib = mem_gib - self._settings.SANDBOX_HOST_HEADROOM_GIB
        mem_limit_gib = _parse_mem_limit(self._settings.SANDBOX_MEM_LIMIT)
        max_by_mem = int(available_gib // mem_limit_gib) if mem_limit_gib > 0 else 0
        max_by_cpu = int(ncpu // self._settings.SANDBOX_CPUS) if self._settings.SANDBOX_CPUS > 0 else 0
        derived = min(max_by_mem, max_by_cpu)
        if self._settings.SANDBOX_POOL_MAX_OVERRIDE is not None:
            if self._settings.SANDBOX_POOL_MAX_OVERRIDE > derived:
                logger.warning(
                    "SANDBOX_POOL_MAX_OVERRIDE=%d exceeds derived ceiling %d; clamping",
                    self._settings.SANDBOX_POOL_MAX_OVERRIDE, derived,
                )
            derived = min(derived, self._settings.SANDBOX_POOL_MAX_OVERRIDE)
        return max(self._settings.SANDBOX_POOL_MIN, derived)

    async def _check_image(self) -> None:
        image = self._settings.SANDBOX_IMAGE
        force_rebuild = self._settings.SANDBOX_REBUILD

        missing = False
        if not force_rebuild:
            try:
                await asyncio.to_thread(self._client.images.get, image)
            except ImageNotFound:
                missing = True
        else:
            logger.info("Sandbox image '%s' rebuild requested (SANDBOX_REBUILD)", image)

        if not missing and not force_rebuild:
            return

        if self._settings.SANDBOX_SKIP_BUILD:
            raise RuntimeError(
                f"Sandbox image '{image}' not found and SANDBOX_SKIP_BUILD is set. "
                f"Build it manually: docker compose -f sandbox/docker-compose.yml build"
            )

        sandbox_dir = os.path.join(
            os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "sandbox"
        )
        if not os.path.isdir(sandbox_dir):
            raise RuntimeError(
                f"Sandbox build context not found at {sandbox_dir}; cannot auto-build '{image}'."
            )

        logger.warning("Sandbox image '%s' missing — auto-building from %s", image, sandbox_dir)
        try:
            # api.build either tags the image on success or raises on failure
            # (BuildError, or the build-log error branch in _build_image), so a
            # completed call guarantees the image is present — no re-check needed.
            await asyncio.to_thread(self._build_image, sandbox_dir, image)
        except Exception as exc:
            logger.error("Failed to auto-build sandbox image '%s': %s", image, exc)
            raise RuntimeError(
                f"Sandbox image '{image}' not found and auto-build failed: {exc}. "
                f"Build it manually with: docker compose -f sandbox/docker-compose.yml build"
            ) from exc

    def _build_image(self, sandbox_dir: str, image: str) -> None:
        # api.build streams build logs as decoded dicts. Drain them so the build
        # runs to completion and we surface progress and errors.
        logs = self._client.api.build(
            path=sandbox_dir,
            dockerfile="Dockerfile",
            tag=image,
            rm=True,
            decode=True,
        )
        for entry in logs:
            if "stream" in entry and entry["stream"].strip():
                logger.info("docker build: %s", entry["stream"].strip())
            elif "error" in entry:
                raise RuntimeError(f"docker build error: {entry['error']}")

    async def prewarm(self) -> None:
        self._ensure_async()
        await asyncio.gather(*[self._spawn_to_ready() for _ in range(self._settings.SANDBOX_POOL_MIN)])

    async def _spawn_to_ready(self) -> None:
        worker = await self._create_worker()
        async with self._cond:
            self._live += 1
            await self._ready.put(worker)
            self._cond.notify_all()
        logger.info("Worker %s spawned | live=%d | ready=%d", worker._id, self._live, self._ready.qsize())

    async def _create_worker(self) -> DockerSandboxWorker:
        worker = await asyncio.to_thread(self._run_container)
        try:
            await self._wait_ready(worker)
        except Exception:
            await worker.destroy()
            raise
        return worker

    def _run_container(self) -> DockerSandboxWorker:
        run_kwargs = {
            "mem_limit": self._settings.SANDBOX_MEM_LIMIT,
            "nano_cpus": int(self._settings.SANDBOX_CPUS * 1_000_000_000),
            "read_only": True,
            "tmpfs": {"/tmp": "size=100m"},
            "security_opt": ["no-new-privileges"],
            "detach": True,
            "labels": {"app": "sandbox-pool"},
        }
        network = self._settings.SANDBOX_NETWORK
        if network:
            name = f"sandbox-{secrets.token_hex(4)}"
            container = self._client.containers.run(
                self._settings.SANDBOX_IMAGE,
                name=name,
                network=network,
                **run_kwargs,
            )
            return DockerSandboxWorker(
                container,
                f"http://{name}:{self._settings.SANDBOX_PORT}",
                self._settings.SANDBOX_TIMEOUT,
            )

        container = self._client.containers.run(
            self._settings.SANDBOX_IMAGE,
            ports={f"{self._settings.SANDBOX_PORT}/tcp": None},
            **run_kwargs,
        )
        container.reload()
        bindings = container.ports.get(f"{self._settings.SANDBOX_PORT}/tcp")
        if not bindings:
            container.remove(force=True)
            raise RuntimeError(
                f"Container {container.id[:12]} exposed no port for {self._settings.SANDBOX_PORT}/tcp"
            )
        host_port = bindings[0]["HostPort"]
        parsed = urlparse(self._settings.SANDBOX_URL)
        scheme = parsed.scheme or "http"
        host = parsed.hostname or "localhost"
        base = f"{scheme}://{host}"
        return DockerSandboxWorker(container, f"{base}:{host_port}", self._settings.SANDBOX_TIMEOUT)

    async def _wait_ready(self, worker: DockerSandboxWorker, timeout: Optional[int] = None) -> None:
        # Health alone is not enough: a worker whose image bootstrapped with a
        # broken builtins/exec env passes /health yet cannot run real workloads.
        # Probe the actual execution capability so a bad worker never enters the
        # pool (and so deterministic env bugs surface at ingress, not mid-turn).
        timeout = timeout or self._settings.SANDBOX_CONTAINER_READY_TIMEOUT
        start = time.monotonic()
        async with httpx.AsyncClient(timeout=2.0) as client:
            while True:
                try:
                    resp = await client.get(f"{worker._base_url}/probe")
                    if resp.status_code == 200 and resp.json().get("status") == "ok":
                        return
                except Exception:
                    pass
                if time.monotonic() - start > timeout:
                    raise RuntimeError(f"Worker {worker._id} not ready/capable after {timeout}s")
                await asyncio.sleep(0.3)

    async def acquire(self) -> DockerSandboxWorker:
        self._ensure_async()
        # Fast path: pop a warm worker if available.
        try:
            return self._ready.get_nowait()
        except asyncio.QueueEmpty:
            pass
        async with self._cond:
            while True:
                try:
                    return self._ready.get_nowait()
                except asyncio.QueueEmpty:
                    if self._live >= self._max_live:
                        # Pool is saturated: wait for a released slot, then retry.
                        # A released worker is destroyed (not returned to _ready),
                        # so on wake we must re-check and are allowed to spawn.
                        await self._cond.wait()
                        continue
                    self._live += 1
                    break
        # Spawn outside the lock so other acquires are not blocked.
        try:
            return await self._create_worker()
        except Exception:
            async with self._cond:
                self._live -= 1
                self._cond.notify_all()
            raise

    async def release_destroyed(self, worker: DockerSandboxWorker) -> None:
        self._ensure_async()
        await worker.destroy()
        async with self._cond:
            self._live -= 1
            self._cond.notify_all()
        # Replenish the warm floor in the background. Keep a strong reference to
        # the task so it cannot be garbage-collected before it completes.
        if self._ready.qsize() + self._live < self._settings.SANDBOX_POOL_MIN:
            self._spawn_bg_task(self._replenish())

    def _spawn_bg_task(self, coro):
        task = asyncio.create_task(coro)
        self._bg_tasks.add(task)
        task.add_done_callback(self._bg_tasks.discard)
        return task

    async def _replenish(self) -> None:
        while True:
            async with self._cond:
                if self._ready.qsize() >= self._settings.SANDBOX_POOL_MIN:
                    return
                if self._live >= self._max_live:
                    return
                self._live += 1
            try:
                worker = await self._create_worker()
            except Exception:
                async with self._cond:
                    self._live -= 1
                    self._cond.notify_all()
                logger.warning("Sandbox worker replenish failed", exc_info=True)
                return
            async with self._cond:
                if self._stop:
                    # Shutdown raced ahead of this replenish; discard the worker.
                    await worker.destroy()
                    return
                await self._ready.put(worker)
                self._cond.notify_all()

    async def shutdown(self) -> None:
        self._ensure_async()
        self._stop = True
        async with self._cond:
            workers = []
            while not self._ready.empty():
                workers.append(self._ready.get_nowait())
            self._live = 0
        await asyncio.gather(*[w.destroy() for w in workers], return_exceptions=True)
        # Sweep any containers spawned by background replenish tasks we could not
        # track (labelled at creation so a direct Docker query finds them all).
        try:
            await asyncio.to_thread(self._sweep)
        except Exception as e:
            logger.warning("Sandbox pool sweep failed: %s", e)
        logger.info("Sandbox pool shut down | destroyed=%d", len(workers))

    def _sweep(self) -> None:
        for container in self._client.containers.list(filters={"label": "app=sandbox-pool"}, all=True):
            try:
                container.remove(force=True)
            except Exception:
                pass


class PooledSandbox(SandboxClient):
    """SandboxClient backed by the disposable Docker worker pool."""

    def __init__(self, settings=None):
        self._settings = settings or get_settings()
        self._pool = DockerSandboxPool(None, self._settings)
        self._connected = False
        # Best-effort cleanup if the graceful lifespan shutdown is skipped
        # (reload-mode kills, SIGINT, uncaught exceptions). These handlers run
        # outside the asyncio loop, so they only do the synchronous container
        # sweep — no event loop, no await.
        self._register_emergency_cleanup()

    def _register_emergency_cleanup(self) -> None:
        import atexit
        import signal as _signal

        atexit.register(self._emergency_sweep)
        try:
            # SIGINT/SIGTERM run the sweep then fall through to default handling
            # so the process still exits. Register only in the main thread.
            _signal.signal(_signal.SIGINT, self._on_signal)
            _signal.signal(_signal.SIGTERM, self._on_signal)
        except (ValueError, OSError):
            # Not in main thread, or platform lacks the signal — atexit still covers exit.
            logger.debug("Sandbox signal handler not registered; atexit sweep remains.")

    def _on_signal(self, signum, frame) -> None:
        self._emergency_sweep()
        # Re-raise so the default handler can terminate the process normally.
        import signal as _signal
        prev = _signal.getsignal(signum)
        if callable(prev) and not isinstance(prev, int):
            prev(signum, frame)
        else:
            raise _signal.default_int_handler(signum, frame)

    def _emergency_sweep(self) -> None:
        """Force-remove all pool containers, even if the async shutdown didn't run."""
        client = getattr(self._pool, "_client", None)
        if client is None:
            return
        try:
            for container in client.containers.list(filters={"label": "app=sandbox-pool"}, all=True):
                try:
                    container.remove(force=True)
                except Exception:
                    pass
            logger.info("Sandbox emergency sweep complete")
        except Exception as e:
            logger.warning("Sandbox emergency sweep failed: %s", e)

    async def prewarm(self) -> None:
        # Build the Docker client; fail loudly if Docker/ image is unavailable.
        self._pool._client = docker.from_env()
        await self._pool.connect()
        await self._pool.prewarm()
        self._connected = True

    async def execute(
        self,
        code: str,
        session_id: str,
        dataframes: Optional[Dict[str, bytes]] = None,
    ) -> Dict[str, Any]:
        if not self._connected:
            raise RuntimeError("PooledSandbox not connected; call prewarm() during startup.")
        worker = await self._pool.acquire()
        try:
            return await worker.execute(code, session_id=session_id, dataframes=dataframes)
        finally:
            await self._pool.release_destroyed(worker)

    async def close(self) -> None:
        await self._pool.shutdown()
