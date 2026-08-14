<?php

namespace Tests\Unit\Services;

use App\Services\ClientSignatureService;
use Tests\TestCase;

class ClientSignatureServiceTest extends TestCase
{
    private string $privateKey;
    private string $publicKey;
    private ClientSignatureService $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateKey = bin2hex(random_bytes(32));
        $kp = sodium_crypto_sign_seed_keypair(hex2bin($this->privateKey));
        $this->publicKey = bin2hex(sodium_crypto_sign_publickey($kp));
        $this->signer = new ClientSignatureService(300);
    }

    public function test_sign_then_verify_round_trip(): void
    {
        $body = '{"query":"hi","client_id":"5"}';
        $signed = $this->signer->sign($body, $this->privateKey);

        $this->assertTrue(
            $this->signer->verify($body, $signed['signature'], $this->publicKey, $signed['timestamp'])
        );
    }

    public function test_verify_rejects_tampered_body(): void
    {
        $body = '{"query":"hi","client_id":"5"}';
        $signed = $this->signer->sign($body, $this->privateKey);

        $this->assertFalse(
            $this->signer->verify('tampered', $signed['signature'], $this->publicKey, $signed['timestamp'])
        );
    }

    public function test_verify_rejects_wrong_key(): void
    {
        $body = '{"query":"hi","client_id":"5"}';
        $signed = $this->signer->sign($body, $this->privateKey);

        $other = bin2hex(random_bytes(32));
        $otherKp = sodium_crypto_sign_seed_keypair(hex2bin($other));
        $otherPublic = bin2hex(sodium_crypto_sign_publickey($otherKp));

        $this->assertFalse(
            $this->signer->verify($body, $signed['signature'], $otherPublic, $signed['timestamp'])
        );
    }

    public function test_verify_rejects_stale_timestamp(): void
    {
        $body = '{"query":"hi"}';
        $signed = $this->signer->sign($body, $this->privateKey);

        $staleTs = $signed['timestamp'] - 600;

        $this->assertFalse(
            $this->signer->verify($body, $signed['signature'], $this->publicKey, $staleTs)
        );
    }

    public function test_verify_rejects_future_timestamp(): void
    {
        $body = '{"query":"hi"}';
        $signed = $this->signer->sign($body, $this->privateKey);

        $futureTs = $signed['timestamp'] + 600;

        $this->assertFalse(
            $this->signer->verify($body, $signed['signature'], $this->publicKey, $futureTs)
        );
    }

    public function test_sign_empty_body(): void
    {
        $body = '';
        $signed = $this->signer->sign($body, $this->privateKey);

        $this->assertTrue(
            $this->signer->verify($body, $signed['signature'], $this->publicKey, $signed['timestamp'])
        );
    }

    public function test_canonical_format_matches_python(): void
    {
        $body = '{"query":"hi"}';
        $timestamp = 1700000000;
        $canonical = $this->signer->canonical($body, $timestamp);

        $expected = $timestamp . ':' . hash('sha256', $body);
        $this->assertSame($expected, $canonical);
    }
}
