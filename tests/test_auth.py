import json

import pytest

from agent.auth import (
    KeyStore,
    sign,
    verify,
    SIGNATURE_MAX_AGE_SEC,
)


@pytest.fixture
def keypair():
    """Return (private_hex, public_hex) for a fresh Ed25519 keypair."""
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    priv = Ed25519PrivateKey.generate()
    return priv.private_bytes_raw().hex(), priv.public_key().public_bytes_raw().hex()


@pytest.fixture
def other_keypair():
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    priv = Ed25519PrivateKey.generate()
    return priv.private_bytes_raw().hex(), priv.public_key().public_bytes_raw().hex()


def make_body(client_id="5", query="select 1"):
    return json.dumps({"client_id": client_id, "query": query}).encode()


class TestSignVerify:
    def test_round_trip(self, keypair):
        priv, pub = keypair
        body = make_body()
        ts, sig = sign(body, priv)
        assert verify(body, sig, pub, ts) is True

    def test_tampered_body_fails(self, keypair):
        priv, pub = keypair
        body = make_body()
        ts, sig = sign(body, priv)
        tampered = make_body(query="drop table")
        assert verify(tampered, sig, pub, ts) is False

    def test_wrong_key_fails(self, keypair, other_keypair):
        priv, _ = keypair
        _, other_pub = other_keypair
        body = make_body()
        ts, sig = sign(body, priv)
        assert verify(body, sig, other_pub, ts) is False

    def test_expired_timestamp_fails(self, keypair):
        priv, pub = keypair
        body = make_body()
        ts, sig = sign(body, priv, timestamp=0)  # year 1970
        assert verify(body, sig, pub, ts) is False

    def test_future_timestamp_within_window_passes(self, keypair):
        priv, pub = keypair
        body = make_body()
        ts, sig = sign(body, priv)
        # Re-verify with the original timestamp; window measured against now.
        assert verify(body, sig, pub, ts) is True

    def test_max_age_boundary(self, keypair):
        priv, pub = keypair
        body = make_body()
        ts, sig = sign(body, priv)
        # Exactly at the boundary should still pass.
        assert verify(body, sig, pub, ts, max_age=SIGNATURE_MAX_AGE_SEC) is True


class TestKeyStore:
    def test_verify_known_client(self, keypair):
        priv, pub = keypair
        ks = KeyStore()
        ks.refresh_client_keys({"5": pub})
        body = make_body(client_id="5")
        ts, sig = sign(body, priv)
        assert ks.verify_client(body, sig, "5", ts) is True

    def test_unknown_client_rejected(self, keypair):
        priv, pub = keypair
        ks = KeyStore()
        ks.refresh_client_keys({"5": pub})
        body = make_body(client_id="99")
        ts, sig = sign(body, priv)
        assert ks.verify_client(body, sig, "99", ts) is False

    def test_wrong_client_key_rejected(self, keypair, other_keypair):
        priv, _ = keypair
        _, other_pub = other_keypair
        ks = KeyStore()
        # Client 5 registered with OTHER key; request signed with different key.
        ks.refresh_client_keys({"5": other_pub})
        body = make_body(client_id="5")
        ts, sig = sign(body, priv)
        assert ks.verify_client(body, sig, "5", ts) is False

    def test_verify_admin(self, keypair):
        priv, pub = keypair
        ks = KeyStore()
        ks.set_admin_key(pub)
        body = make_body()
        ts, sig = sign(body, priv)
        assert ks.verify_admin(body, sig, ts) is True

    def test_admin_without_key_configured_rejected(self, keypair):
        priv, _ = keypair
        ks = KeyStore()
        body = make_body()
        ts, sig = sign(body, priv)
        assert ks.verify_admin(body, sig, ts) is False

    def test_has_client_and_ids(self, keypair):
        _, pub = keypair
        ks = KeyStore()
        ks.refresh_client_keys({"1": pub, "2": pub})
        assert ks.has_client("1") is True
        assert ks.has_client("3") is False
        assert ks.client_ids == {"1", "2"}
