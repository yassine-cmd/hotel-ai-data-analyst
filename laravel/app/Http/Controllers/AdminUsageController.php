<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\TokenUsage;
use Illuminate\Http\JsonResponse;

class AdminUsageController extends Controller
{
    public function index(): JsonResponse
    {
        // The admin dashboard is cost-only: every figure is the SUM of the
        // gateway-reported billed cost (`token_usage.cost_usd`, OpenRouter
        // usage.cost). Rows recorded before cost reporting have NULL cost and
        // count as $0.
        $totals = TokenUsage::selectRaw('COALESCE(SUM(cost_usd), 0) as cost_usd')->first();

        $since = now()->subDays(29)->startOfDay();
        $dailyRows = TokenUsage::where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $since->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $day,
                'cost' => round((float) ($dailyRows->get($day)?->cost_usd ?? 0), 6),
            ];
        }

        $clientRows = TokenUsage::selectRaw('client_id, COALESCE(SUM(cost_usd), 0) as cost_usd')
            ->groupBy('client_id')
            ->get();

        $clientNames = Client::whereIn('id', $clientRows->pluck('client_id'))->pluck('name', 'id');

        $perClient = $clientRows->map(function ($row) use ($clientNames) {
            $users = TokenUsage::where('client_id', $row->client_id)
                ->selectRaw(
                    'COALESCE(user_id, 0) as user_id, '
                    . 'COALESCE(NULLIF(user_name, ""), "Unknown") as user_name, '
                    . 'COALESCE(SUM(cost_usd), 0) as cost_usd'
                )
                ->groupBy('user_id', 'user_name')
                ->orderByDesc('cost_usd')
                ->get()
                ->map(fn ($u) => [
                    'user_id' => (int) $u->user_id,
                    'user_name' => $u->user_name,
                    'cost' => round((float) $u->cost_usd, 6),
                ]);

            return [
                'client_id' => $row->client_id,
                'name' => $clientNames->get($row->client_id, $row->client_id),
                'cost' => round((float) $row->cost_usd, 6),
                'users' => $users,
            ];
        })->sortByDesc('cost')->values();

        $topClients = $perClient->take(5)->values();

        return response()->json([
            'totals' => [
                'cost' => round((float) ($totals->cost_usd ?? 0), 6),
            ],
            'series' => $series,
            'per_client' => $perClient,
            'top_clients' => $topClients,
        ]);
    }
}