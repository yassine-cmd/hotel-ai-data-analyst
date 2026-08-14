<?php

namespace App\Console\Commands;

use App\Services\ClientSignatureService;
use Illuminate\Console\Command;

class GenerateClientKeys extends Command
{
    protected $signature = 'client:keys:generate';
    protected $description = 'Generate an Ed25519 keypair for a client Laravel instance (prints PRIVATE for CLIENT_PRIVATE_KEY and PUBLIC for clients.public_key)';

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
        $this->line('Set PRIVATE in this instance\'s <comment>CLIENT_PRIVATE_KEY</comment> env var.');
        $this->line('Register PUBLIC in the shared DB (<comment>clients.public_key</comment>) via the admin panel.');

        return 0;
    }
}
