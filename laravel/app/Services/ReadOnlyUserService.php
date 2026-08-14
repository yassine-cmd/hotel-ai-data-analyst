<?php

namespace App\Services;

use App\Models\Client;
use App\Support\ClientCredentialCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDO;

class ReadOnlyUserService
{
    private const DB_NAME_PATTERN = '/^[A-Za-z0-9_]+$/';

    private const USERNAME_PATTERN = '/^fms_[a-z0-9]+_[a-f0-9]{8}$/';

    /**
     * Deterministic, per-client username: fms_<sanitized id>_<md5(id)[:8]>.
     * Always derived from the client id so we can never cross client boundaries.
     */
    public function usernameForClient(Client $client): string
    {
        $sanitized = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $client->id));
        $prefix = substr($sanitized, 0, 12);
        $suffix = substr(md5((string) $client->id), 0, 8);

        return 'fms_' . $prefix . '_' . $suffix;
    }

    /**
     * Create (or recreate) the read-only user and grant SELECT on the database.
     * Returns the freshly generated password.
     */
    public function provision(string $username, string $database, PDO $pdo): string
    {
        $generated = bin2hex(random_bytes(16));
        $pdo->exec("DROP USER IF EXISTS '" . $username . "'@'%'");
        $pdo->exec("CREATE USER '" . $username . "'@'%' IDENTIFIED BY '" . $generated . "'");
        $pdo->exec("GRANT SELECT ON `{$database}`.* TO '" . $username . "'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");

        return $generated;
    }

    /**
     * Drop the read-only user.
     */
    public function deprovision(string $username, PDO $pdo): void
    {
        $pdo->exec("DROP USER IF EXISTS '" . $username . "'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");
    }

    /**
     * Drop exactly this client's read-only user from its analytics DB.
     * The username is always derived from the client id — never from the
     * stored value — so deleting a client can never wipe another client's user.
     */
    public function deprovisionFromClient(Client $client): void
    {
        $this->validateDatabaseName($client->analytics_db_name);
        $username = $this->usernameForClient($client);
        $this->validateUsername($username);

        $this->deprovision($username, $this->adminPdoFor($client));
    }

    /**
     * Idempotently guarantee the client has a working read-only user. Called
     * the first time the data plane connects to the client's DB (and by the
     * `fms:provision` command). Creates the user with the client's admin
     * credentials if it is missing or its stored password no longer works.
     *
     * Returns 'ok' (already working), 'created' (newly provisioned), or
     * 'fixed' (stored password was stale, re-provisioned).
     */
    public function ensureClientAgentUser(Client $client): string
    {
        $database = $client->analytics_db_name;
        $this->validateDatabaseName($database);

        $username = $this->usernameForClient($client);
        $this->validateUsername($username);

        $desired = null;
        try {
            $desired = $client->decrypted_agent_password;
        } catch (\Throwable $e) {
            // stale / undecryptable stored credential
        }

        if ($desired !== null && $this->canConnect($client, $username, $desired)) {
            $this->persistUsername($client, $username);

            return 'ok';
        }

        $generated = $this->provision($username, $database, $this->adminPdoFor($client));

        $client->analytics_agent_user = $username;
        $client->analytics_agent_password = ClientCredentialCipher::encrypt($generated);
        $client->save();

        Log::info('Analytics read-only user provisioned', [
            'client_id' => $client->id,
            'username' => $username,
            'recreated' => $desired !== null,
        ]);

        return $desired === null ? 'created' : 'fixed';
    }

    /**
     * Reconcile a single client's read-only user, cached so we don't re-verify
     * on every request. Failures are retried after a short backoff.
     */
    public function ensureClientAgentUserIfNeeded(Client $client): void
    {
        $key = 'fms:agent:client:' . $client->id . ':' . md5((string) config('credentials.key', ''));

        if (Cache::has($key)) {
            return;
        }

        try {
            $this->ensureClientAgentUser($client);
            Cache::put($key, true, 300);
        } catch (\Throwable $e) {
            Cache::put($key, 'error:' . $e->getMessage(), 60);
            Log::warning("Analytics read-only user reconciliation failed for client {$client->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure every active client has a working read-only user. Returns a map
     * of client id => status. Used by the `fms:provision` command.
     */
    public function reconcileAll(): array
    {
        $results = [];
        foreach (Client::where('is_active', true)->get() as $client) {
            try {
                $results[$client->id] = $this->ensureClientAgentUser($client);
            } catch (\Throwable $e) {
                $results[$client->id] = 'error: ' . $e->getMessage();
            }
        }

        return $results;
    }

    private function canConnect(Client $client, string $username, string $password): bool
    {
        try {
            new PDO(
                "mysql:host={$client->analytics_db_host};port={$client->analytics_db_port};dbname={$client->analytics_db_name};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function adminPdoFor(Client $client): PDO
    {
        $this->validateDatabaseName($client->analytics_db_name);

        try {
            $adminPassword = $client->decrypted_admin_password;
        } catch (DecryptException $e) {
            throw new \RuntimeException(
                "Client {$client->id} admin analytics credential cannot be decrypted "
                . '(CLIENT_CREDENTIALS_KEY mismatch). Re-save it with '
                . "`php artisan clients:set-credentials {$client->id} --admin-password=…`."
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Client {$client->id} admin analytics credential is unavailable: " . $e->getMessage()
            );
        }

        return new PDO(
            "mysql:host={$client->analytics_db_host};port={$client->analytics_db_port};dbname={$client->analytics_db_name};charset=utf8mb4",
            $client->analytics_admin_user,
            $adminPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );
    }

    private function persistUsername(Client $client, string $username): void
    {
        if ($client->analytics_agent_user !== $username) {
            $client->analytics_agent_user = $username;
            $client->save();
        }
    }

    private function validateDatabaseName(string $database): void
    {
        if (!preg_match(self::DB_NAME_PATTERN, $database)) {
            throw new InvalidArgumentException("Invalid database name: '{$database}' (allowed: A-Z, a-z, 0-9, _)");
        }
    }

    private function validateUsername(string $username): void
    {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new InvalidArgumentException("Refusing to manage unexpected username: '{$username}'");
        }
    }
}