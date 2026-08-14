<?php

namespace App\Http\Middleware;

use App\Services\TokenCostService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientAllowed
{
    public function __construct(
        private TokenCostService $costService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->client_id) {
            return $next($request);
        }

        $client = $user->client;

        if ((!$client || !$client->is_active) || $user->deactivated_at !== null) {
            abort(403, 'Your account has been deactivated. Contact an administrator.');
        }

        if ($request->is('api/analyze') && $client->budget_limit_usd !== null) {
            if ($this->costService->monthSpendForClient($client) >= $client->budget_limit_usd) {
                abort(429, 'Quota exceeded. Your monthly budget has been reached.');
            }
        }

        return $next($request);
    }
}
