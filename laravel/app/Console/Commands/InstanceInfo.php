<?php

namespace App\Console\Commands;

use App\Services\InstanceIdentity;
use Illuminate\Console\Command;

class InstanceInfo extends Command
{
    protected $signature = 'fms:instance';
    protected $description = 'Print this Laravel instance\'s resolved role (admin/client) and keypair status';

    public function handle(): int
    {
        $hasAdminKey = (string) config('python-proxy.admin_private_key', '') !== '';
        $hasClientKey = (string) config('python-proxy.client_private_key', '') !== '';

        $this->line('Keypair:');
        $this->line('  ADMIN_PRIVATE_KEY set: ' . ($hasAdminKey ? 'yes' : 'no'));
        $this->line('  CLIENT_PRIVATE_KEY set: ' . ($hasClientKey ? 'yes' : 'no'));

        if (!$hasAdminKey && !$hasClientKey) {
            $this->error('Misconfigured: neither ADMIN_PRIVATE_KEY nor CLIENT_PRIVATE_KEY is set.');
            return 1;
        }

        try {
            $identity = app(InstanceIdentity::class);
            $isAdmin = $identity->isAdmin();
            $clientId = $identity->clientId();
        } catch (\Throwable $e) {
            $this->error('Instance identity could not be resolved: ' . $e->getMessage());
            return 1;
        }

        $role = $isAdmin && $clientId !== null ? 'ADMIN + CLIENT'
            : ($isAdmin ? 'ADMIN' : 'CLIENT');

        $this->newLine();
        $this->line('Resolved role: ' . $role);
        $this->line('Resolved client_id: ' . ($clientId === null ? '(none — admin instance)' : $clientId));

        return 0;
    }
}
