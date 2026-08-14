<?php

namespace App\Services;

use App\Models\Client;
use App\Models\TokenUsage;
use Illuminate\Support\Carbon;

class TokenCostService
{
    /**
     * Reported spend over the immutable billing ledger: the SUM of the
     * gateway-reported `cost_usd` (OpenRouter usage.cost) per turn.
     *
     * This is authoritative — it bakes in provider routing, prompt-cache
     * discounts, and reasoning-token billing that no local price table can
     * reproduce. Rows recorded before cost reporting existed have a NULL
     * `cost_usd` and count as $0 (no backfill; dashboards are accurate from
     * the switch date forward).
     */
    public function spendForClient(Client $client, Carbon $since): float
    {
        return (float) TokenUsage::where('client_id', $client->id)
            ->where('created_at', '>=', $since)
            ->sum('cost_usd');
    }

    public function monthSpendForClient(Client $client): float
    {
        return $this->spendForClient($client, now()->startOfMonth());
    }
}
