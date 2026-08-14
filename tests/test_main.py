import secrets

import httpx
import pytest
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

from agent.auth import KeyStore, key_store, sign

CLIENT_PRIV = secrets.token_hex(32)
CLIENT_PUB = Ed25519PrivateKey.from_private_bytes(bytes.fromhex(CLIENT_PRIV)).public_key().public_bytes_raw().hex()

ADMIN_PRIV = secrets.token_hex(32)
ADMIN_PUB = Ed25519PrivateKey.from_private_bytes(bytes.fromhex(ADMIN_PRIV)).public_key().public_bytes_raw().hex()


@pytest.fixture(autouse=True)
def _keystore():
    key_store.refresh_client_keys({"5": CLIENT_PUB})
    key_store.set_admin_key(ADMIN_PUB)
    yield
    key_store.refresh_client_keys({})
    key_store.set_admin_key("")


def _client_headers(body: bytes, priv: str = CLIENT_PRIV):
    ts, sig = sign(body, priv)
    return {"X-Timestamp": str(ts), "X-Signature": sig}


def _admin_headers(body: bytes):
    return _client_headers(body, ADMIN_PRIV)


@pytest.fixture
def app():
    from main import app
    return app


def _transport(app):
    return httpx.ASGITransport(app=app)


@pytest.mark.asyncio
class TestAnalyzeEndpoint:
    async def test_unsigned_request_rejected(self, app):
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/internal/analyze", json={"query": "hi", "client_id": "5"})
        assert resp.status_code == 403
        assert "Missing signature" in resp.json()["detail"]

    async def test_signed_request_passes_auth(self, app):
        body = b'{"query": "hi", "client_id": "5", "data_plane_url": "http://127.0.0.1:80/api/internal/data/v1"}'
        headers = {**_client_headers(body), "Content-Type": "application/json"}
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/internal/analyze", content=body, headers=headers)
        assert resp.status_code != 403

    async def test_bad_signature_rejected(self, app):
        body = b'{"query": "hi", "client_id": "5"}'
        ts, _ = sign(body, CLIENT_PRIV)
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/internal/analyze", content=body, headers={
                "X-Timestamp": str(ts), "X-Signature": "deadbeef",
                "Content-Type": "application/json",
            })
        assert resp.status_code == 403
        assert "Invalid signature" in resp.json()["detail"]

    async def test_unknown_client_rejected(self, app):
        body = b'{"query": "hi", "client_id": "999", "data_plane_url": "http://127.0.0.1:80/api/internal/data/v1"}'
        headers = {**_client_headers(body), "Content-Type": "application/json"}
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/internal/analyze", content=body, headers=headers)
        assert resp.status_code == 403


@pytest.mark.asyncio
class TestSessionEndpoints:
    async def test_history_unsigned_rejected(self, app):
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.get("/internal/sessions/5/s1/history")
        assert resp.status_code == 403

    async def test_history_signed_passes_auth(self, app):
        headers = _client_headers(b"")
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.get("/internal/sessions/5/s1/history", headers=headers)
        assert resp.status_code != 403

    async def test_history_reports_session_error(self, app):
        from main import session_manager
        await session_manager.get_or_create("serr", "5")
        await session_manager.record_error("serr", "5", {
            "code": "STREAM_ERROR", "message": "The analysis failed.", "retryable": True, "query": "q",
        })
        headers = _client_headers(b"")
        try:
            async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
                resp = await c.get("/internal/sessions/5/serr/history", headers=headers)
            assert resp.status_code == 200
            assert resp.json()["session_error"]["code"] == "STREAM_ERROR"
            assert resp.json()["session_error"]["query"] == "q"
        finally:
            await session_manager.delete("serr", "5")

    async def test_download_unsigned_rejected(self, app):
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.get("/internal/sessions/5/s1/artifacts/df1/download")
        assert resp.status_code == 403

    async def test_cleanup_unsigned_rejected(self, app):
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/internal/sessions/5/s1/cleanup")
        assert resp.status_code == 403


@pytest.mark.asyncio
class TestAdminEndpoints:
    async def test_admin_register_unsigned_rejected(self, app):
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/admin/keys/register", json={"client_id": "10", "public_key": "aabb"})
        assert resp.status_code == 403

    async def test_admin_register_signed_with_admin_key(self, app):
        body = b'{"client_id": "10", "public_key": "aabbccdd"}'
        headers = {**_admin_headers(body), "Content-Type": "application/json"}
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/admin/keys/register", content=body, headers=headers)
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"
        assert key_store._client_keys["10"] == "aabbccdd"

    async def test_admin_register_missing_fields_rejected(self, app):
        body = b'{"client_id": "10"}'
        headers = {**_admin_headers(body), "Content-Type": "application/json"}
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/admin/keys/register", content=body, headers=headers)
        assert resp.status_code == 400

    async def test_admin_register_with_client_key_rejected(self, app):
        body = b'{"client_id": "10", "public_key": "aabbccdd"}'
        headers = {**_client_headers(body), "Content-Type": "application/json"}
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.post("/admin/keys/register", content=body, headers=headers)
        assert resp.status_code == 403

    async def test_admin_list_empty_body_works(self, app):
        body = b""
        headers = _admin_headers(body)
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.get("/admin/keys", headers=headers)
        assert resp.status_code == 200

    async def test_admin_delete_empty_body_works(self, app):
        body = b""
        headers = _admin_headers(body)
        async with httpx.AsyncClient(transport=_transport(app), base_url="http://test") as c:
            resp = await c.delete("/admin/keys/5", headers=headers)
        assert resp.status_code == 200
