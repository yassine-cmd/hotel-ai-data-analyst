<?php

return [
    'base_url' => env('PYTHON_SERVICE_URL', 'http://127.0.0.1:8000'),

    // Instance authentication (Ed25519). Each Laravel instance owns a client
    // keypair and/or an admin keypair. An instance may be a client instance, an
    // admin instance, or both (dual-role, e.g. a dev box).
    //
    // CLIENT INSTANCE: CLIENT_PRIVATE_KEY (raw 32-byte Ed25519 seed, hex) lives in
    // this instance's .env. Its matching public key is registered in the shared
    // DB (clients.public_key) by the admin. This instance signs every analyze
    // request to Python; Python verifies it against the registered public key.
    'client_private_key' => env('CLIENT_PRIVATE_KEY', ''),

    // ADMIN INSTANCE: ADMIN_PRIVATE_KEY (raw 32-byte Ed25519 seed, hex) lives in
    // the admin instance's .env. Python holds the matching ADMIN_PUBLIC_KEY in its
    // own env and verifies admin-signed calls (/admin/keys/*) against it. The
    // admin Laravel instance never needs the public key — presence of this
    // private key is what makes the instance the admin.
    'admin_private_key' => env('ADMIN_PRIVATE_KEY', ''),

    // Central Python's Ed25519 public key (hex). This instance verifies the
    // signature on every data-plane response/call from Python against this.
    // Same value on every instance (it is Python's key, not theirs).
    'python_public_key' => env('PYTHON_PUBLIC_KEY', ''),

    // Ed25519 signature replay window (seconds). Must match Python's
    // SIGNATURE_MAX_AGE_SEC (300).
    'signature_max_age' => (int) env('SIGNATURE_MAX_AGE', 300),

    // This instance's own data-plane URL. Python calls back here to execute SQL
    // for this client. In a single-server setup this is the app URL +
    // /api/internal/data/v1; override per-instance when the data plane is reached
    // at a different host/port than the public entry point.
    'data_plane_self_url' => env('DATA_PLANE_SELF_URL', env('APP_URL', 'http://127.0.0.1:80') . '/api/internal/data/v1'),

    // Delegation token lifetime (seconds) granted to Python for data-plane
    // calls. Tokens are rotated on every analyze(); this only bounds how long a
    // stale token remains usable (default 1 hour).
    'delegation_ttl' => (int) env('DELEGATION_TTL', 3600),

    // System audit log forwarding. Client instances forward audit events to
    // Python (which relays them to the admin instance's log file). Leave empty
    // on the admin instance — it is the log sink. Events stay local to this
    // instance when unset. See App\Services\AuditLogger.
    'audit_forward_url' => env('SYSTEM_AUDIT_FORWARD_URL', ''),

    // Retention (days) for the daily system-audit files; 0 keeps them forever.
    'audit_retention_days' => (int) env('SYSTEM_AUDIT_RETENTION_DAYS', 30),

    'query_timeout_ms' => (int) env('AGENT_QUERY_TIMEOUT_MS', 30000),
    'stream_timeout' => (int) env('AGENT_STREAM_TIMEOUT', 180),
    'socket_connect_timeout' => (int) env('PYTHON_SOCKET_CONNECT_TIMEOUT', 5),
];
