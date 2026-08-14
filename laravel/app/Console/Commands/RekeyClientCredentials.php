<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Support\ClientCredentialCipher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Rotate CLIENT_CREDENTIALS_KEY: decrypt every client's analytics credentials
 * with the current key, re-encrypt with a new key, then update .env. Because
 * the old key is still in hand during rotation, no plaintext is ever needed.
 */
class RekeyClientCredentials extends Command
{
    protected $signature = 'clients:rekey {--key= : The new base64 32-byte key (omit to generate one)}';

    protected $description = 'Rotate CLIENT_CREDENTIALS_KEY and re-encrypt all clients\' analytics credentials (updates .env)';

    public function handle(): int
    {
        $newKey = $this->option('key') ?? base64_encode(random_bytes(32));
        if (strlen(base64_decode($newKey, true)) !== 32) {
            $this->error('--key must be a base64-encoded 32-byte value (use `php artisan clients:key:generate` output).');
            return 1;
        }

        $clients = Client::all();
        if ($clients->isEmpty()) {
            $this->warn('No clients to re-encrypt.');
        }

        foreach ($clients as $client) {
            try {
                $agent = $client->decrypted_agent_password;
            } catch (DecryptException $e) {
                $this->error("Client {$client->id} ({$client->name}): agent credentials cannot be decrypted with the current key.");
                $this->error('Fix it first with `php artisan clients:set-credentials ' . $client->id . '`, then re-run clients:rekey.');
                return 1;
            }

            try {
                $admin = $client->decrypted_admin_password;
            } catch (DecryptException $e) {
                $this->error("Client {$client->id} ({$client->name}): admin credentials cannot be decrypted with the current key.");
                $this->error('Fix it first with `php artisan clients:set-credentials ' . $client->id . '`, then re-run clients:rekey.');
                return 1;
            }

            $client->forceFill([
                'analytics_agent_password' => $this->reEncrypt($agent, $newKey),
                'analytics_admin_password' => $this->reEncrypt($admin, $newKey),
            ])->save();
        }

        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            $this->error("Could not update {$envPath} — set CLIENT_CREDENTIALS_KEY=$newKey manually.");
            return 1;
        }

        $contents = (string) file_get_contents($envPath);
        $line = 'CLIENT_CREDENTIALS_KEY=' . $newKey;
        if (preg_match('/^CLIENT_CREDENTIALS_KEY=.*$/m', $contents)) {
            $contents = preg_replace('/^CLIENT_CREDENTIALS_KEY=.*$/m', $line, $contents);
        } else {
            $contents .= PHP_EOL . $line . PHP_EOL;
        }
        file_put_contents($envPath, $contents);

        $this->info('All clients re-encrypted.');
        $this->info('Updated .env with CLIENT_CREDENTIALS_KEY=' . $newKey);
        $this->warn('Keep the previous key somewhere safe until you are sure everything works (see docs/admin-guide.md).');

        return 0;
    }

    private function reEncrypt(string $value, string $newKey): string
    {
        $key = base64_decode($newKey, true);
        $cipher = new \Illuminate\Encryption\Encrypter($key, 'AES-256-CBC');

        return $cipher->encryptString($value);
    }
}