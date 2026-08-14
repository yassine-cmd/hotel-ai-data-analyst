<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Config;

/**
 * Resolves which tenant the current instance belongs to, derived solely from
 * its Ed25519 keypair. No configurable client id is used, so a deployed
 * instance cannot be repointed at another tenant by editing .env.
 *
 * An instance may be an admin instance, a client instance, or both (dual-role,
 * e.g. a dev box that runs the admin panel and also serves one client):
 *  - ADMIN_PRIVATE_KEY set  -> the instance is the admin instance (serves Admin
 *    users and signs admin calls). Python verifies admin-signed calls against
 *    ADMIN_PUBLIC_KEY, which lives in the Python env, not here.
 *  - CLIENT_PRIVATE_KEY set  -> the derived public key is matched against
 *    clients.public_key to find the served client.
 *
 * If neither key is set the instance is misconfigured and authentication fails
 * closed. Both keys may be set: the instance is then admin AND resolves to its
 * client tenant (so it can authenticate both Admin users and that client's users).
 */
class InstanceIdentity
{
    private bool $resolved = false;
    private bool $isAdmin = false;
    private ?int $clientId = null;

    public function __construct(
        private ClientSignatureService $signature,
    ) {}

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        $adminKey = (string) Config::get('python-proxy.admin_private_key', '');
        $clientKey = (string) Config::get('python-proxy.client_private_key', '');

        if ($adminKey === '' && $clientKey === '') {
            throw new \RuntimeException(
                'Instance keypair is not configured. Set ADMIN_PRIVATE_KEY (admin), '
                . 'CLIENT_PRIVATE_KEY (client), or both for a dual-role instance.'
            );
        }

        // Admin capability: presence of the admin key makes this the admin instance.
        if ($adminKey !== '') {
            $this->isAdmin = true;
        }

        // Client capability: resolve the served tenant from the client key.
        if ($clientKey !== '') {
            $publicKey = $this->signature->publicKeyFromPrivate($clientKey);
            $client = Client::where('public_key', $publicKey)->first();
            if ($client) {
                $this->clientId = $client->id;
            } elseif ($adminKey === '') {
                // Pure client instance whose key matches no tenant — fail closed.
                throw new \RuntimeException('Instance keypair does not match any registered client.');
            }
            // Dual-role instance with an unmatched client key: still admin,
            // clientId stays null.
        }
    }

    public function isAdmin(): bool
    {
        $this->resolve();
        return $this->isAdmin;
    }

    public function clientId(): ?int
    {
        $this->resolve();
        return $this->clientId;
    }
}
