<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateCredentialKey extends Command
{
    protected $signature = 'clients:key:generate';
    protected $description = 'Generate a CLIENT_CREDENTIALS_KEY (base64, 32 bytes) for encrypting client analytics-DB credentials at rest';

    public function handle(): int
    {
        $key = base64_encode(random_bytes(32));

        $this->line('<info>CLIENT_CREDENTIALS_KEY</info>=' . $key);
        $this->newLine();
        $this->line('Set it in <comment>.env</comment> (config/credentials.php -> credentials.key).');
        $this->line('Set it once per deployment. Rotate only via <comment>php artisan clients:rekey</comment> —');
        $this->line('never let `php artisan key:generate` touch it. See <comment>docs/admin-guide.md</comment>.');

        return 0;
    }
}