<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SessionMetadata;
use App\Models\TokenUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists turn-level token usage (billing) reported by Python via the
 * turn-completion endpoint. `session_metadata` carries running session totals;
 * `token_usage` keeps one row per turn for time series. A `turn_uuid` makes
 * the write idempotent so Python's POST retries can't double-bill.
 */
class TokenUsageService
{
    public function recordUsage(string $sessionId, Client $client, ?int $userId, ?string $userName, array $meta, ?string $turnUuid = null): void
    {
        $turnTotal = max(0, (int) ($meta['turn_tokens'] ?? 0));
        $turnPrompt = max(0, (int) ($meta['turn_prompt_tokens'] ?? 0));
        $turnCompletion = max(0, (int) ($meta['turn_completion_tokens'] ?? 0));
        $turnReasoning = max(0, (int) ($meta['turn_reasoning_tokens'] ?? 0));
        $turnCacheHit = max(0, (int) ($meta['turn_cache_hit_tokens'] ?? 0));
        $turnCacheMiss = max(0, (int) ($meta['turn_cache_miss_tokens'] ?? 0));
        $turnCost = max(0.0, (float) ($meta['turn_cost_usd'] ?? 0));

        if ($turnTotal <= 0) {
            Log::debug('Token usage skipped (provider reported none)', [
                'session_id' => $sessionId,
                'client_id' => $client->id,
            ]);
            return;
        }

        try {
            DB::transaction(function () use ($sessionId, $client, $userId, $userName, $turnTotal, $turnPrompt, $turnCompletion, $turnReasoning, $turnCacheHit, $turnCacheMiss, $turnCost, $turnUuid) {
                if ($turnUuid !== null) {
                    $duplicate = TokenUsage::where('client_id', $client->id)
                        ->where('session_id', $sessionId)
                        ->where('turn_uuid', $turnUuid)
                        ->exists();
                    if ($duplicate) {
                        Log::info('Turn usage already recorded (idempotent skip)', [
                            'session_id' => $sessionId,
                            'client_id' => $client->id,
                            'turn_uuid' => $turnUuid,
                        ]);
                        return;
                    }
                }

                $meta = SessionMetadata::where('session_id', $sessionId)
                    ->where('client_id', $client->id)
                    ->first();

                if ($meta) {
                    $meta->increment('total_tokens', $turnTotal);
                    $meta->increment('prompt_tokens', $turnPrompt);
                    $meta->increment('completion_tokens', $turnCompletion);
                    $meta->increment('reasoning_tokens', $turnReasoning);
                    if ($meta->user_id === null && $userId !== null) {
                        $meta->user_id = $userId;
                        $meta->user_name = $userName;
                        $meta->save();
                    }
                } else {
                    SessionMetadata::create([
                        'session_id' => $sessionId,
                        'client_id' => $client->id,
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'name' => '',
                        'turn_count' => 0,
                        'total_tokens' => $turnTotal,
                        'prompt_tokens' => $turnPrompt,
                        'completion_tokens' => $turnCompletion,
                        'reasoning_tokens' => $turnReasoning,
                        'path' => "{$client->id}/{$sessionId}/",
                        'created_at' => now(),
                        'last_access' => now(),
                    ]);
                }

                TokenUsage::create([
                    'session_id' => $sessionId,
                    'client_id' => $client->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'turn_uuid' => $turnUuid,
                    'prompt_tokens' => $turnPrompt,
                    'cache_hit_tokens' => $turnCacheHit,
                    'cache_miss_tokens' => $turnCacheMiss,
                    'completion_tokens' => $turnCompletion,
                    'reasoning_tokens' => $turnReasoning,
                    'total_tokens' => $turnTotal,
                    'cost_usd' => $turnCost,
                    // Explicit UTC stamp (matches SessionMetadata) so the
                    // billing ledger aligns with the app's UTC monthly window;
                    // the column default would use the MySQL server timezone.
                    'created_at' => now(),
                ]);
            });

            Log::info('Token usage recorded', [
                'session_id' => $sessionId,
                'client_id' => $client->id,
                'user_id' => $userId,
                'tokens' => $turnTotal,
                'prompt' => $turnPrompt,
                'cache_hit' => $turnCacheHit,
                'cache_miss' => $turnCacheMiss,
                'completion' => $turnCompletion,
                'reasoning' => $turnReasoning,
                'cost_usd' => $turnCost,
            ]);
        } catch (\Throwable $e) {
            Log::error('Token usage recording failed', [
                'session_id' => $sessionId,
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
