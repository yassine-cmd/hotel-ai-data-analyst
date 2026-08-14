<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SessionMetadata;
use App\Models\TokenUsage;

class ClientUsageService
{
    public function __construct(
        private TokenCostService $costService
    ) {}

    /**
     * Aggregate per-user usage over the immutable ledger (one row per user that
     * has recorded usage), including estimated cost. Not paginated — used by the
     * admin client dashboard to merge usage onto paginated user records.
     *
     * @return \Illuminate\Support\Collection<int, array<string, int|string|float>>
     */
    public function getPerUserUsage(Client $client, ?int $userId = null): \Illuminate\Support\Collection
    {
        return TokenUsage::where('client_id', $client->id)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->selectRaw(
                'COALESCE(user_id, 0) as user_id, '
                . 'COALESCE(NULLIF(user_name, ""), "Unknown") as user_name, '
                . 'COALESCE(SUM(total_tokens), 0) as total_tokens, '
                . 'COALESCE(SUM(prompt_tokens), 0) as prompt_tokens, '
                . 'COALESCE(SUM(completion_tokens), 0) as completion_tokens, '
                . 'COALESCE(SUM(reasoning_tokens), 0) as reasoning_tokens, '
                . 'COALESCE(SUM(cache_hit_tokens), 0) as cache_hit_tokens, '
                . 'COALESCE(SUM(cache_miss_tokens), 0) as cache_miss_tokens, '
                . 'COALESCE(SUM(cost_usd), 0) as cost_usd'
            )
            ->groupBy('user_id', 'user_name')
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn ($u) => [
                'user_id' => (int) $u->user_id,
                'user_name' => $u->user_name,
                'total_tokens' => (int) $u->total_tokens,
                'prompt_tokens' => (int) $u->prompt_tokens,
                'completion_tokens' => (int) $u->completion_tokens,
                'reasoning_tokens' => (int) $u->reasoning_tokens,
                'cost_usd' => round((float) $u->cost_usd, 6),
            ]);
    }

    /**
     * Aggregate usage for a single client. Totals are lifetime aggregates over
     * the immutable token_usage ledger (per-turn billing rows), not
     * session_metadata, so deleting conversations does not erase usage.
     *
     * @param  array{page?: int, per_page?: int, users_page?: int, users_per_page?: int, include_per_user?: bool}  $opts
     */
    public function getClientDashboard(Client $client, array $opts = []): array
    {
        $page = (int) ($opts['page'] ?? 1);
        $perPage = min((int) ($opts['per_page'] ?? config('pagination.default')), config('pagination.max'));
        $usersPage = (int) ($opts['users_page'] ?? 1);
        $usersPerPage = min((int) ($opts['users_per_page'] ?? config('pagination.default')), config('pagination.max'));
        $includePerUser = (bool) ($opts['include_per_user'] ?? true);
        $userId = isset($opts['user_id']) ? (int) $opts['user_id'] : null;

        $totals = TokenUsage::where('client_id', $client->id)
            ->selectRaw(
                'COALESCE(SUM(total_tokens), 0) as total_tokens, '
                . 'COALESCE(SUM(prompt_tokens), 0) as prompt_tokens, '
                . 'COALESCE(SUM(cache_hit_tokens), 0) as cache_hit_tokens, '
                . 'COALESCE(SUM(completion_tokens), 0) as completion_tokens, '
                . 'COALESCE(SUM(reasoning_tokens), 0) as reasoning_tokens, '
                . 'COALESCE(SUM(cost_usd), 0) as cost_usd'
            )
            ->first();

        $sessionsQuery = SessionMetadata::where('client_id', $client->id)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('created_at', 'desc');

        $totalSessions = $sessionsQuery->count();
        $lastPage = max(1, (int) ceil($totalSessions / $perPage));
        $page = min($page, $lastPage);

        $sessions = $sessionsQuery
            ->forPage($page, $perPage)
            ->get(['session_id', 'user_id', 'user_name', 'name', 'turn_count', 'total_tokens', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'created_at', 'last_access'])
            ->map(fn ($s) => [
                'session_id' => $s->session_id,
                'user_id' => $s->user_id,
                'user_name' => $s->user_name,
                'name' => $s->name,
                'turn_count' => $s->turn_count,
                'total_tokens' => $s->total_tokens,
                'prompt_tokens' => $s->prompt_tokens,
                'completion_tokens' => $s->completion_tokens,
                'reasoning_tokens' => $s->reasoning_tokens,
                'created_at' => $s->created_at?->toIso8601String(),
                'last_access' => $s->last_access?->toIso8601String(),
            ]);

        if ($includePerUser) {
            $perUserRows = $this->getPerUserUsage($client, $userId);

            $totalUsers = $perUserRows->count();
            $lastUsersPage = max(1, (int) ceil($totalUsers / $usersPerPage));
            $usersPage = min($usersPage, $lastUsersPage);

            $perUser = $perUserRows
                ->slice(($usersPage - 1) * $usersPerPage, $usersPerPage)
                ->values();
        }

        $spend = $this->costService->monthSpendForClient($client);

        $data = [
            'totals' => [
                'total_tokens' => (int) $totals->total_tokens,
                'prompt_tokens' => (int) $totals->prompt_tokens,
                'completion_tokens' => (int) $totals->completion_tokens,
                'reasoning_tokens' => (int) $totals->reasoning_tokens,
                'cost' => round((float) $totals->cost_usd, 6),
            ],
            'budget' => [
                'limit_usd' => $client->budget_limit_usd,
                'spend_usd' => round($spend, 4),
                'remaining_usd' => $client->budget_limit_usd === null ? null : round(max(0, $client->budget_limit_usd - $spend), 4),
                'period_start' => now()->startOfMonth()->toDateString(),
            ],
            'sessions' => $sessions,
            'sessions_meta' => [
                'total' => $totalSessions,
                'page' => (int) $page,
                'per_page' => (int) $perPage,
                'last_page' => (int) $lastPage,
            ],
        ];

        if ($includePerUser) {
            $data['per_user'] = $perUser;
            $data['per_user_meta'] = [
                'total' => $totalUsers,
                'page' => (int) $usersPage,
                'per_page' => (int) $usersPerPage,
                'last_page' => (int) $lastUsersPage,
            ];
        }

        return $data;
    }
}