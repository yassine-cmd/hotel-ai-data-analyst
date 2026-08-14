<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Support\ClientCredentialCipher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

class SetClientCredentials extends Command implements PromptsForMissingInput
{
    protected $signature = 'clients:set-credentials {client : The client id} {--password= : The analytics agent DB password} {--admin-password= : The analytics admin DB password}';

    protected $description = "Re-encrypt a client's analytics DB credentials with the current CLIENT_CREDENTIALS_KEY (run after a key loss / rotation)";

    public function handle(): int
    {
        $client = Client::find((int) $this->argument('client'));
        if (!$client) {
            $this->error("Client {$this->argument('client')} not found.");
            return 1;
        }

        $passwordOption = $this->option('password');
        if ($passwordOption !== null) {
            // Explicitly passed on the CLI; an empty value is allowed.
            $client->analytics_agent_password = ClientCredentialCipher::encrypt($passwordOption);
            $this->info("Client {$client->id} agent password re-encrypted.");
        } else {
            $password = $this->secret('Analytics agent DB password (blank to leave unchanged)');
            if ($password !== null && $password !== '') {
                $client->analytics_agent_password = ClientCredentialCipher::encrypt($password);
                $this->info("Client {$client->id} agent password re-encrypted.");
            }
        }

        $adminOption = $this->option('admin-password');
        if ($adminOption !== null) {
            // Explicitly passed on the CLI; an empty value is allowed
            // (e.g. local dev where the analytics admin is root with no password).
            $client->analytics_admin_password = ClientCredentialCipher::encrypt($adminOption);
            $this->info("Client {$client->id} admin password re-encrypted.");
        } else {
            $adminPassword = $this->secret('Analytics admin DB password (blank to leave unchanged)');
            if ($adminPassword !== null && $adminPassword !== '') {
                $client->analytics_admin_password = ClientCredentialCipher::encrypt($adminPassword);
                $this->info("Client {$client->id} admin password re-encrypted.");
            }
        }

        $client->save();

        return 0;
    }
}