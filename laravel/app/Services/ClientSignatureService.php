<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ed25519 request signing and verification for the instance<->Python channel.
 *
 * Signing scheme (must match Python's agent/auth.py exactly):
 *   canonical = "<timestamp>:<sha256_hex(raw_body)>"
 *   signature = Ed25519_sign(canonical, private_key_seed)
 *
 * The body hash binds the signature to the exact bytes sent, and the timestamp
 * (verified within SIGNATURE_MAX_AGE_SEC on the other side) prevents replay.
 *
 * Keys are raw Ed25519 seeds/public bytes encoded as hex (no PEM/libodium
 * envelope) so they round-trip cleanly between PHP's sodium and Python's
 * `cryptography` library.
 */
class ClientSignatureService
{
    public function __construct(
        private int $maxAge = 300,
    ) {}

    /**
     * Generate a fresh Ed25519 keypair.
     *
     * @return array{private_key:string, public_key:string}  Raw 32-byte keys, hex-encoded.
     */
    public function generate(): array
    {
        $seed = random_bytes(32);
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return [
            'private_key' => bin2hex($seed),
            'public_key'  => bin2hex(sodium_crypto_sign_publickey($keypair)),
        ];
    }

    /**
     * Build the canonical string that gets signed/verified.
     */
    public function canonical(string $body, int $timestamp): string
    {
        return $timestamp . ':' . hash('sha256', $body);
    }

    /**
     * Sign a raw body with this instance's private key.
     *
     * The stored key is a raw 32-byte Ed25519 seed (hex). sodium signs with a
     * 64-byte secret key, so we derive the keypair from the seed first. This
     * keeps the on-disk private key identical to what Python's
     * Ed25519PrivateKey.from_private_bytes() consumes.
     *
     * @param  string  $body  Raw request body bytes.
     * @param  string  $privateKeyHex  Raw 32-byte Ed25519 seed, hex-encoded.
     * @return array{timestamp:int, signature:string}  Signature header values.
     */
    /**
     * Derive the Ed25519 public key (hex) from a private-key seed (hex).
     *
     * Used to determine which tenant an instance belongs to without storing a
     * separate, spoofable identifier: the instance's public key is matched
     * against the registered client keys.
     */
    public function publicKeyFromPrivate(string $privateKeyHex): string
    {
        $seed = hex2bin($privateKeyHex);
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return bin2hex(sodium_crypto_sign_publickey($keypair));
    }

    public function sign(string $body, string $privateKeyHex): array
    {
        $timestamp = time();
        $canonical = $this->canonical($body, $timestamp);

        $seed = hex2bin($privateKeyHex);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $signature = sodium_crypto_sign_detached($canonical, $secretKey);

        return [
            'timestamp' => $timestamp,
            'signature' => bin2hex($signature),
        ];
    }

    /**
     * Verify a detached Ed25519 signature over a raw body.
     *
     * @param  string  $body  Raw request body bytes.
     * @param  string  $signatureHex  Hex-encoded signature.
     * @param  string  $publicKeyHex  Raw 32-byte Ed25519 public key, hex-encoded.
     * @param  int  8  $timestamp  Unix timestamp from the request headers.
     */
    public function verify(string $body, string $signatureHex, string $publicKeyHex, int $timestamp): bool
    {
        if (abs(time() - $timestamp) > $this->maxAge) {
            Log::warning('Signature rejected: stale timestamp', [
                'timestamp' => $timestamp,
                'max_age' => $this->maxAge,
            ]);
            return false;
        }

        try {
            $canonical = $this->canonical($body, $timestamp);
            $publicKey = hex2bin($publicKeyHex);
            $signature = hex2bin($signatureHex);

            return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey);
        } catch (\Throwable $e) {
            Log::warning('Signature rejected: ' . $e->getMessage(), [
                'public_key' => substr($publicKeyHex, 0, 12) . '...',
            ]);
            return false;
        }
    }

    // ── Delegation tokens ─────────────────────────────────────────────────
    // A delegation token authorizes Python to call the data-plane on behalf of
    // this client instance. It is a JWT-like structure signed with the client's
    // existing keypair (no new key material). Format:
    //   base64url(header) "." base64url(payload) "." base64url(signature)

    /**
     * Create a delegation token authorizing Python to make data-plane calls
     * for this client during this session.
     */
    public function createDelegation(string $clientId, string $sessionId, string $privateKeyHex, int $ttlSeconds = 3600): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'Ed25519', 'typ' => 'delegation']));

        $now = time();
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => 'laravel-instance',
            'aud' => 'python-data-plane',
            'client_id' => (string) $clientId,
            'session_id' => (string) $sessionId,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => Str::uuid()->toString(),
        ]));

        $signingInput = $header . '.' . $payload;
        $signature = $this->signClaim($signingInput, $privateKeyHex);

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Verify a delegation token and return its claims.
     *
     * @return array{client_id:string, session_id:string, iat:int, exp:int, jti:string}|null
     */
    public function verifyDelegation(string $token, string $expectedClientId, string $publicKeyHex): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            Log::warning('Delegation rejected: malformed token');
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $signature = $this->base64UrlDecode($sigB64);
        if ($signature === false) {
            Log::warning('Delegation rejected: bad signature encoding');
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        if (!$this->verifyClaim($signingInput, $signature, $publicKeyHex)) {
            Log::warning('Delegation rejected: invalid signature');
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64) ?? '', true);
        if (!is_array($payload)) {
            Log::warning('Delegation rejected: bad payload');
            return null;
        }

        if (($payload['aud'] ?? '') !== 'python-data-plane') {
            Log::warning('Delegation rejected: wrong audience');
            return null;
        }

        if (($payload['exp'] ?? 0) < time()) {
            Log::warning('Delegation rejected: expired', ['exp' => $payload['exp']]);
            return null;
        }

        if (($payload['client_id'] ?? '') !== (string) $expectedClientId) {
            Log::warning('Delegation rejected: client_id mismatch');
            return null;
        }

        return [
            'client_id' => $payload['client_id'],
            'session_id' => $payload['session_id'] ?? '',
            'iat' => $payload['iat'] ?? 0,
            'exp' => $payload['exp'] ?? 0,
            'jti' => $payload['jti'] ?? '',
        ];
    }

    private function signClaim(string $input, string $privateKeyHex): string
    {
        $seed = hex2bin($privateKeyHex);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        return sodium_crypto_sign_detached($input, $secretKey);
    }

    private function verifyClaim(string $input, string $signature, string $publicKeyHex): bool
    {
        try {
            return sodium_crypto_sign_verify_detached(
                $signature,
                $input,
                hex2bin($publicKeyHex)
            );
        } catch (\Throwable $e) {
            Log::warning('Claim verification failed: ' . $e->getMessage());
            return false;
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
