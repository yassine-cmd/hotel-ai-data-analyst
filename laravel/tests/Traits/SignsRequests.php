<?php

namespace Tests\Traits;

use App\Services\ClientSignatureService;

/**
 * Signs outgoing JSON requests with a test Ed25519 keypair so controller tests
 * exercise the real signature-verification path instead of the old API key.
 * Also generates delegation tokens for the data-plane endpoint.
 */
trait SignsRequests
{
    private ?ClientSignatureService $signer = null;
    private string $testPrivateKey = '';
    private string $testPublicKey = '';

    protected function setUpSigning(): void
    {
        $this->testPrivateKey = bin2hex(random_bytes(32));
        $kp = sodium_crypto_sign_seed_keypair(hex2bin($this->testPrivateKey));
        $this->testPublicKey = bin2hex(sodium_crypto_sign_publickey($kp));
        $this->signer = new ClientSignatureService((int) config('python-proxy.signature_max_age', 300));
        // The controller verifies against this public key.
        config(['python-proxy.python_public_key' => $this->testPublicKey]);
        // Register the client's public key so delegation verification works.
        $client = \App\Models\Client::first();
        if ($client) {
            $client->public_key = $this->testPublicKey;
            $client->save();
        }
    }

    protected function getTestPublicKey(): string
    {
        return $this->testPublicKey;
    }

    /**
     * POST JSON with a valid delegation token for the data-plane endpoint.
     */
    protected function postDelegatedJson(string $uri, array $data = [], array $headers = [])
    {
        $clientId = isset($data['datasource_id'])
            ? (int) str_replace('.analytics', '', $data['datasource_id'])
            : 0;

        $token = $this->signer->createDelegation(
            (string) $clientId,
            $data['session_id'] ?? 'test-session',
            $this->testPrivateKey,
            7200
        );

        return $this->withHeaders(array_merge($headers, [
            'X-Delegation-Token' => $token,
        ]))->postJson($uri, $data);
    }

    /**
     * POST JSON with an intentionally invalid delegation token.
     */
    protected function postDelegatedJsonBadToken(string $uri, array $data = [])
    {
        return $this->withHeaders([
            'X-Delegation-Token' => 'invalid.token.here',
        ])->postJson($uri, $data);
    }
}
