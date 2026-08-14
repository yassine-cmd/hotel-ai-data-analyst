<?php

namespace App\Console\Commands;

use App\Services\ClientSignatureService;
use Illuminate\Console\Command;

class GenerateAdminKeys extends Command
{
    protected $signature = 'admin:keys:generate';
    protected $description = 'Generate the admin Ed25519 keypair (prints PRIVATE for the admin Laravel ADMIN_PRIVATE_KEY and PUBLIC for the Python ADMIN_PUBLIC_KEY)';

    public function handle(): int
    {
        if (!extension_loaded('sodium')) {
            $this->error('The sodium extension is not loaded. Enable extension=sodium in php.ini.');
            return 1;
        }

        $keypair = app(ClientSignatureService::class)->generate();

        $this->line('<info>PRIVATE</info>=' . $keypair['private_key']);
        $this->line('<info>PUBLIC</info>=' . $keypair['public_key']);
        $this->newLine();
        $this->line('Set PRIVATE in the admin Laravel instance\'s <comment>ADMIN_PRIVATE_KEY</comment> env var.');
        $this->line('Set PUBLIC in the Python agent\'s <comment>ADMIN_PUBLIC_KEY</comment> env var (Python verifies admin-signed calls against it).');

        return 0;
    }
}
