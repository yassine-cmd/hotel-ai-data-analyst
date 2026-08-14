<?php

namespace Tests\Feature\Controllers;

use App\Models\TokenUsage;
use Tests\TestCase;

class AdminUsageControllerTest extends TestCase
{
    public function test_admin_usage_returns_cost_aggregates(): void
    {
        $admin = $this->createAdminUser();
        $clientA = $this->createClient();
        $clientB = $this->createClient();
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $clientA->id]);
        $bob = $this->createUser(['name' => 'Bob', 'client_id' => $clientA->id]);

        TokenUsage::create([
            'session_id' => 's1', 'client_id' => $clientA->id,
            'user_id' => $alice->id, 'user_name' => 'Alice',
            'total_tokens' => 1000, 'prompt_tokens' => 700, 'completion_tokens' => 300,
            'cost_usd' => 0.0001, 'created_at' => now()->subDays(2),
        ]);
        TokenUsage::create([
            'session_id' => 's2', 'client_id' => $clientA->id,
            'user_id' => $bob->id, 'user_name' => 'Bob',
            'total_tokens' => 4000, 'prompt_tokens' => 3000, 'completion_tokens' => 1000,
            'cost_usd' => 0.0002, 'created_at' => now()->subDays(1),
        ]);
        TokenUsage::create([
            'session_id' => 's3', 'client_id' => $clientB->id,
            'user_id' => null, 'user_name' => null,
            'total_tokens' => 500, 'prompt_tokens' => 400, 'completion_tokens' => 100,
            'cost_usd' => 0.00005, 'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'admin')->getJson('/api/admin/usage');

        $response->assertOk();

        // Totals are cost-only: the SUM of the gateway-reported billed cost.
        $this->assertEqualsWithDelta(0.00035, (float) $response->json('totals.cost'), 0.0000001);

        $this->assertCount(30, $response->json('series'));
        $this->assertEqualsWithDelta(0.00005, (float) $response->json('series.29.cost'), 0.0000001);
        $this->assertEqualsWithDelta(0.0001, (float) $response->json('series.27.cost'), 0.0000001);

        $perClient = $response->json('per_client');
        $this->assertCount(2, $perClient);
        // clientA sorted first: it has the higher cumulative cost.
        $this->assertSame($clientA->id, $perClient[0]['client_id']);
        $this->assertSame('Test Hotel', $perClient[0]['name']);
        $this->assertEqualsWithDelta(0.0003, (float) $perClient[0]['cost'], 0.0000001);
        $this->assertCount(2, $perClient[0]['users']);
        $this->assertSame('Bob', $perClient[0]['users'][0]['user_name']);
        $this->assertEqualsWithDelta(0.0002, (float) $perClient[0]['users'][0]['cost'], 0.0000001);
        $this->assertEqualsWithDelta(0.0001, (float) $perClient[0]['users'][1]['cost'], 0.0000001);
        $this->assertSame($clientB->id, $perClient[1]['client_id']);
        $this->assertEqualsWithDelta(0.00005, (float) $perClient[1]['cost'], 0.0000001);

        $this->assertCount(2, $response->json('top_clients'));
        $this->assertSame('Test Hotel', $response->json('top_clients.0.name'));
        $this->assertEqualsWithDelta(0.0003, (float) $response->json('top_clients.0.cost'), 0.0000001);
    }

    public function test_admin_usage_rejects_non_admin(): void
    {
        $this->getJson('/api/admin/usage')->assertStatus(401);

        $client = $this->createClient();
        $user = $this->createUser(['client_id' => $client->id]);

        $this->actingAs($user)->getJson('/api/admin/usage')->assertForbidden();
    }

    public function test_admin_usage_empty_database(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin, 'admin')->getJson('/api/admin/usage');

        $response->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $response->json('totals.cost'), 0.0000001);
        $this->assertCount(30, $response->json('series'));
        $this->assertSame([], $response->json('per_client'));
        $this->assertSame([], $response->json('top_clients'));
    }
}