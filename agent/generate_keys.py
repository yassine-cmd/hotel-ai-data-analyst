"""Generate an Ed25519 keypair for a Laravel instance.

Run on the machine that needs the keypair (each hotel instance and the admin
instance each run this once). Print the PRIVATE key for the instance's .env and
the PUBLIC key for the admin to register in the shared DB.

    python -m agent.generate_keys
    PRIVATE=<hex>     # paste into the instance's .env as CLIENT_PRIVATE_KEY
    PUBLIC=<hex>      # admin stores this in clients.public_key (or admin key file)
"""

import secrets

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey


def generate() -> tuple[str, str]:
    """Return ``(private_hex, public_hex)`` for a fresh Ed25519 keypair."""
    priv = Ed25519PrivateKey.generate()
    priv_bytes = priv.private_bytes_raw()
    pub_bytes = priv.public_key().public_bytes_raw()
    return priv_bytes.hex(), pub_bytes.hex()


def main() -> None:
    priv, pub = generate()
    print(f"PRIVATE={priv}")
    print(f"PUBLIC={pub}")


if __name__ == "__main__":
    main()
