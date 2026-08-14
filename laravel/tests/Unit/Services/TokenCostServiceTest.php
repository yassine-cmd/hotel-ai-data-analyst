<?php

namespace Tests\Unit\Services;

use App\Models\TokenUsage;
use App\Services\TokenCostService;
use Tests\TestCase;

class TokenCostServiceTest extends TestCase
{
    private TokenCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TokenCostService();
    }

    private function record(array $overrides = []): TokenUsage
    {
        $client = $this->createClient();
        $user = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        return TokenUsage::create(array_merge([
            'session_id' => 'svc-' . uniqid(),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'total_tokens' => 1000,
            'prompt_tokens' => 800,
            'completion_tokens' => 200,
            'cost_usd' => 0.000123,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_spend_is_the_sum_of_gateway_reported_cost(): void
    {
        $client = $this->createClient();
        $user = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        TokenUsage::create([
            'session_id' => 't1', 'client_id' => $client->id,
            'user_id' => $user->id, 'user_name' => 'Alice',
            'total_tokens' => 1000, 'prompt_tokens' => 800, 'completion_tokens' => 200,
            'cost_usd' => 0.0001, 'created_at' => now(),
        ]);
        TokenUsage::create([
            'session_id' => 't2', 'client_id' => $client->id,
            'user_id' => $user->id, 'user_name' => 'Alice',
            'total_tokens' => 2000, 'prompt_tokens' => 1600, 'completion_tokens' => 400,
            'cost_usd' => 0.0002, 'created_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.0003, $this->service->spendForClient($client, now()->subDay()), 0.0000001);
    }

    public function test_rows_without_cost_count_as_zero(): void
    {
        $client = $this->createClient();
        $user = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        // Legacy row recorded before cost reporting: cost_usd is NULL.
        TokenUsage::create([
            'session_id' => 'legacy', 'client_id' => $client->id,
            'user_id' => $user->id, 'user_name' => 'Alice',
            'total_tokens' => 900000, 'prompt_tokens' => 800000, 'completion_tokens' => 100000,
            'created_at' => now(),
        ]);
        // Modern row: cost_usd populated.
        TokenUsage::create([
            'session_id' => 'modern', 'client_id' => $client->id,
            'user_id' => $user->id, 'user_name' => 'Alice',
            'total_tokens' => 100, 'prompt_tokens' => 80, 'completion_tokens' => 20,
            'cost_usd' => 0.00012, 'created_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.00012, $this->service->spendForClient($client, now()->subDay()), 0.0000001);
    }

    public function test_spend_is_scoped_to_client_and_since(): void
    {
        $clientA = $this->createClient();
        $clientB = $this->createClient();
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $clientA->id]);
        $bob = $this->createUser(['name' => 'Bob', 'client_id' => $clientA->id]);

        TokenUsage::create([
            'session_id' => 'a1', 'client_id' => $clientA->id,
            'user_id' => $alice->id, 'user_name' => 'Alice',
            'total_tokens' => 500, 'prompt_tokens' => 400, 'completion_tokens' => 100,
            'cost_usd' => 0.00005, 'created_at' => now(),
        ]);
        // Same timestamp, different user -> still counts toward the client.
        TokenUsage::create([
            'session_id' => 'a2', 'client_id' => $clientA->id,
            'user_id' => $bob->id, 'user_name' => 'Bob',
            'total_tokens' => 500, 'prompt_tokens' => 400, 'completion_tokens' => 100,
            'cost_usd' => 0.00005, 'created_at' => now(),
        ]);
        // Another client's rows must not leak into A.
        TokenUsage::create([
            'session_id' => 'b1', 'client_id' => $clientB->id,
            'user_id' => null, 'user_name' => null,
            'total_tokens' => 9999, 'prompt_tokens' => 9000, 'completion_tokens' => 999,
            'cost_usd' => 9.99, 'created_at' => now(),
        ]);
        // Row before `since` is excluded.
        TokenUsage::create([
            'session_id' => 'old', 'client_id' => $clientA->id,
            'user_id' => $alice->id, 'user_name' => 'Alice',
            'total_tokens' => 500, 'prompt_tokens' => 400, 'completion_tokens' => 100,
            'cost_usd' => 99.0, 'created_at' => now()->subMonth(),
        ]);

        $this->assertEqualsWithDelta(0.0001, $this->service->spendForClient($clientA, now()->subDay()), 0.0000001);
        $this->assertEqualsWithDelta(0.0, $this->service->spendForClient($clientA, now()->addMinute()), 0.0000001);
    }

    public function test_month_spend_filters_to_current_month(): void
    {
        $client = $this->createClient();
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);
        TokenUsage::create([
            'session_id' => 'm1', 'client_id' => $client->id,
            'user_id' => $alice->id, 'user_name' => 'Alice',
            'total_tokens' => 500, 'prompt_tokens' => 400, 'completion_tokens' => 100,
            'cost_usd' => 0.0002, 'created_at' => now(),
        ]);
        TokenUsage::create([
            'session_id' => 'm0', 'client_id' => $client->id,
            'user_id' => $alice->id, 'user_name' => 'Alice',
            'total_tokens' => 500, 'prompt_tokens' => 400, 'completion_tokens' => 100,
            'cost_usd' => 50.0, 'created_at' => now()->subMonth(),
        ]);

        $this->assertEqualsWithDelta(0.0002, $this->service->monthSpendForClient($client), 0.0000001);
    }

    public function test_zero_spend_for_client_without_rows(): void
    {
        $client = $this->createClient();
        $this->assertSame(0.0, $this->service->spendForClient($client, now()->subYear()));
    }
}