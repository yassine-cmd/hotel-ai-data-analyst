<?php

namespace App\Console\Commands;

use App\Services\ReadOnlyUserService;
use Illuminate\Console\Command;

class ProvisionAgentUsers extends Command
{
    protected $signature = 'fms:provision';
    protected $description = 'Ensure every active client has a working read-only analytics agent DB user (auto-create/recreate as needed).';

    public function handle(): int
    {
        $results = app(ReadOnlyUserService::class)->reconcileAll();

        $this->info('Analytics agent user reconciliation:');
        foreach ($results as $id => $status) {
            $this->line("  client {$id}: {$status}");
        }

        return self::SUCCESS;
    }
}
