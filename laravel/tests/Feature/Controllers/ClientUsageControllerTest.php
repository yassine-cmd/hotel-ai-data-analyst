<?php

namespace Tests\Feature\Controllers;

use App\Models\SessionMetadata;
use App\Models\TokenUsage;
use App\Services\PythonProxyService;
use Tests\TestCase;

class ClientUsageControllerTest extends TestCase
{
    public function test_client_usage_scopes_sessions_and_per_user_to_client_admin(): void
    {
        $client = $this->createClient();
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id, 'permissions' => ['role' => 0, 'permissions' => []]]);
        $bob = $this->createUser(['name' => 'Bob', 'client_id' => $client->id, 'permissions' => ['role' => 1, 'permissions' => ['full_access']]]);

        $makeSession = fn (string $sid, $user, int $tokens, int $daysAgo) => SessionMetadata::create([
            'session_id' => $sid,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'name' => "Session $sid",
            'turn_count' => 2,
            'total_tokens' => $tokens,
            'prompt_tokens' => (int) ($tokens * 0.8),
            'completion_tokens' => (int) ($tokens * 0.2),
            'reasoning_tokens' => (int) ($tokens * 0.1),
            'path' => "{$client->id}/$sid/",
            'created_at' => now()->subDays($daysAgo),
            'last_access' => now()->subDays($daysAgo),
        ]);

        $makeUsage = fn (string $sid, $user, int $tokens, int $daysAgo) => TokenUsage::create([
            'session_id' => $sid,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'total_tokens' => $tokens,
            'prompt_tokens' => (int) ($tokens * 0.8),
            'completion_tokens' => (int) ($tokens * 0.2),
            'reasoning_tokens' => (int) ($tokens * 0.1),
            'created_at' => now()->subDays($daysAgo),
        ]);

        $makeSession('alice-1', $alice, 1000, 5);
        $makeSession('alice-2', $alice, 2500, 2);
        $makeSession('bob-1', $bob, 9999, 1);

        $makeUsage('alice-1', $alice, 1000, 5);
        $makeUsage('alice-2', $alice, 2500, 2);
        $makeUsage('bob-1', $bob, 9999, 1);

        // Client admin (role 1) sees every conversation and the per-staff breakdown.
        $adminResponse = $this->actingAs($bob)->getJson('/api/client/usage');

        $adminResponse->assertOk();
        $sessions = $adminResponse->json('sessions');
        $this->assertCount(3, $sessions);
        $this->assertSame(['bob-1', 'alice-2', 'alice-1'], array_column($sessions, 'session_id'));
        $this->assertSame('Bob', $sessions[0]['user_name']);
        $this->assertSame('Alice', $sessions[1]['user_name']);

        $perUser = $adminResponse->json('per_user');
        $this->assertCount(2, $perUser);
        $this->assertSame('Bob', $perUser[0]['user_name']);
        $this->assertSame(9999, $perUser[0]['total_tokens']);
        $this->assertSame('Alice', $perUser[1]['user_name']);
        $this->assertSame(3500, $perUser[1]['total_tokens']);
        $this->assertArrayHasKey('cost_usd', $perUser[0]);

        // Staff (role 0) only sees their own conversations and their own usage row,
        // while global totals and budget remain client-wide.
        $staffResponse = $this->actingAs($alice)->getJson('/api/client/usage');

        $staffResponse->assertOk();
        $staffSessions = $staffResponse->json('sessions');
        $this->assertCount(2, $staffSessions);
        $this->assertSame(['alice-2', 'alice-1'], array_column($staffSessions, 'session_id'));
        $this->assertCount(1, array_unique(array_column($staffSessions, 'user_id')));
        $this->assertSame($alice->id, $staffSessions[0]['user_id']);

        $staffPerUser = $staffResponse->json('per_user');
        $this->assertCount(1, $staffPerUser);
        $this->assertSame('Alice', $staffPerUser[0]['user_name']);
        $this->assertSame(3500, $staffPerUser[0]['total_tokens']);

        $this->assertSame($adminResponse->json('totals'), $staffResponse->json('totals'));
        $this->assertSame($adminResponse->json('budget'), $staffResponse->json('budget'));
    }

    public function test_client_usage_totals_persist_after_session_deletion(): void
    {
        $client = $this->createClient();
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        SessionMetadata::create([
            'session_id' => 'del-sess',
            'client_id' => $client->id,
            'user_id' => $alice->id,
            'user_name' => $alice->name,
            'name' => 'Doomed',
            'turn_count' => 1,
            'total_tokens' => 0,
            'path' => "{$client->id}/del-sess/",
            'created_at' => now(),
            'last_access' => now(),
        ]);

        TokenUsage::create([
            'session_id' => 'del-sess',
            'client_id' => $client->id,
            'user_id' => $alice->id,
            'user_name' => $alice->name,
            'total_tokens' => 5000,
            'prompt_tokens' => 4000,
            'completion_tokens' => 1000,
            'reasoning_tokens' => 200,
            'created_at' => now(),
        ]);

        $this->actingAs($alice);

        $mock = $this->mock(PythonProxyService::class);
        $mock->shouldReceive('forwardPost')
            ->once()
            ->with('/internal/sessions/' . $client->id . '/del-sess/cleanup')
            ->andReturn(['status' => 'ok']);

        $this->deleteJson('/api/client/sessions/del-sess')->assertOk();
        $this->assertDatabaseMissing('session_metadata', ['session_id' => 'del-sess']);
        $this->assertDatabaseHas('token_usage', ['session_id' => 'del-sess', 'total_tokens' => 5000]);

        $response = $this->getJson('/api/client/usage');

        $response->assertOk();
        $response->assertJsonPath('totals.total_tokens', 5000);
        $response->assertJsonPath('totals.prompt_tokens', 4000);
        $response->assertJsonPath('totals.completion_tokens', 1000);
        $this->assertSame([], $response->json('sessions'));
        $this->assertSame('Alice', $response->json('per_user.0.user_name'));
        $this->assertSame(5000, $response->json('per_user.0.total_tokens'));
    }

    public function test_client_usage_paginates_sessions(): void
    {
        $client = $this->createClient();
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        for ($i = 1; $i <= 15; $i++) {
            SessionMetadata::create([
                'session_id' => "page-sess-$i",
                'client_id' => $client->id,
                'user_id' => $alice->id,
                'user_name' => $alice->name,
                'name' => "Session $i",
                'turn_count' => 1,
                'total_tokens' => $i * 10,
                'path' => "{$client->id}/page-sess-$i/",
                'created_at' => now()->subMinutes(15 - $i),
                'last_access' => now()->subMinutes(15 - $i),
            ]);
            TokenUsage::create([
                'session_id' => "page-sess-$i",
                'client_id' => $client->id,
                'user_id' => $alice->id,
                'user_name' => $alice->name,
                'total_tokens' => $i * 10,
                'prompt_tokens' => (int) ($i * 8),
                'completion_tokens' => (int) ($i * 2),
                'reasoning_tokens' => (int) $i,
                'created_at' => now()->subMinutes(15 - $i),
            ]);
        }

        $page1 = $this->actingAs($alice)->getJson('/api/client/usage?page=1&per_page=10');

        $page1->assertOk();
        $this->assertCount(10, $page1->json('sessions'));
        $this->assertSame('page-sess-15', $page1->json('sessions.0.session_id'));
        $page1->assertJsonPath('sessions_meta.total', 15);
        $page1->assertJsonPath('sessions_meta.page', 1);
        $page1->assertJsonPath('sessions_meta.per_page', 10);
        $page1->assertJsonPath('sessions_meta.last_page', 2);

        $page2 = $this->actingAs($alice)->getJson('/api/client/usage?page=2&per_page=10');

        $page2->assertOk();
        $this->assertCount(5, $page2->json('sessions'));
        $this->assertSame('page-sess-5', $page2->json('sessions.0.session_id'));
        $page2->assertJsonPath('sessions_meta.page', 2);

        // Page beyond the last page clamps to the final page instead of erroring.
        $overflow = $this->actingAs($alice)->getJson('/api/client/usage?page=9&per_page=10');
        $overflow->assertOk();
        $this->assertCount(5, $overflow->json('sessions'));
        $overflow->assertJsonPath('sessions_meta.page', 2);

        // per_page is capped at 100.
        $capped = $this->actingAs($alice)->getJson('/api/client/usage?per_page=999');
        $capped->assertOk();
        $this->assertCount(15, $capped->json('sessions'));
        $capped->assertJsonPath('sessions_meta.per_page', 100);

        // Totals remain client-wide regardless of pagination.
        $page1->assertJsonPath('totals.total_tokens', 1200);
    }

    public function test_client_usage_paginates_per_user(): void
    {
        $client = $this->createClient();

        for ($i = 1; $i <= 5; $i++) {
            $user = $this->createUser(['name' => "User $i", 'client_id' => $client->id]);
            TokenUsage::create([
                'session_id' => "user-sess-$i",
                'client_id' => $client->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'total_tokens' => $i * 100,
                'prompt_tokens' => (int) ($i * 80),
                'completion_tokens' => (int) ($i * 20),
                'reasoning_tokens' => (int) $i,
                'created_at' => now(),
            ]);
        }

        $admin = $this->createUser(['name' => 'Admin', 'client_id' => $client->id, 'permissions' => ['role' => 1, 'permissions' => ['full_access']]]);

        $page1 = $this->actingAs($admin)
            ->getJson('/api/client/usage?users_page=1&users_per_page=2');

        $page1->assertOk();
        $this->assertCount(2, $page1->json('per_user'));
        $this->assertSame('User 5', $page1->json('per_user.0.user_name'));
        $page1->assertJsonPath('per_user_meta.total', 5);
        $page1->assertJsonPath('per_user_meta.page', 1);
        $page1->assertJsonPath('per_user_meta.per_page', 2);
        $page1->assertJsonPath('per_user_meta.last_page', 3);

        $page3 = $this->actingAs($admin)
            ->getJson('/api/client/usage?users_page=3&users_per_page=2');

        $page3->assertOk();
        $this->assertCount(1, $page3->json('per_user'));
        $this->assertSame('User 1', $page3->json('per_user.0.user_name'));
        $page3->assertJsonPath('per_user_meta.page', 3);

        // Users page beyond the last page clamps to the final page.
        $overflow = $this->actingAs($admin)
            ->getJson('/api/client/usage?users_page=9&users_per_page=2');
        $overflow->assertOk();
        $this->assertCount(1, $overflow->json('per_user'));
        $overflow->assertJsonPath('per_user_meta.page', 3);
    }

    public function test_client_usage_includes_budget(): void
    {
        $client = $this->createClient(['budget_limit_usd' => 50]);
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        $response = $this->actingAs($alice)->getJson('/api/client/usage');

        $response->assertOk();
        $response->assertJsonPath('budget.limit_usd', 50);
        $response->assertJsonPath('budget.spend_usd', 0);
        $response->assertJsonPath('budget.remaining_usd', 50);
        $this->assertNotNull($response->json('budget.period_start'));
    }

    public function test_client_usage_budget_spend_matches_reported_cost(): void
    {
        $client = $this->createClient(['budget_limit_usd' => 50]);
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        // Spend is the SUM of the gateway-reported cost, never a local lookup.
        TokenUsage::create([
            'session_id' => 'budget-t1', 'client_id' => $client->id,
            'user_id' => $alice->id, 'user_name' => $alice->name,
            'total_tokens' => 7000, 'prompt_tokens' => 5000, 'completion_tokens' => 2000,
            'cost_usd' => 0.00054, 'created_at' => now(),
        ]);
        TokenUsage::create([
            'session_id' => 'budget-t2', 'client_id' => $client->id,
            'user_id' => $alice->id, 'user_name' => $alice->name,
            'total_tokens' => 7000, 'prompt_tokens' => 5000, 'completion_tokens' => 2000,
            'cost_usd' => 0.00046, 'created_at' => now(),
        ]);

        $response = $this->actingAs($alice)->getJson('/api/client/usage');

        $response->assertOk();
        $this->assertEqualsWithDelta(0.001, (float) $response->json('budget.spend_usd'), 0.001);
        $this->assertEqualsWithDelta(49.999, (float) $response->json('budget.remaining_usd'), 0.001);
        $response->assertJsonPath('budget.limit_usd', 50);
    }

    public function test_client_usage_budget_ignores_prior_month_and_clamps_remaining_at_zero(): void
    {
        $client = $this->createClient(['budget_limit_usd' => 50]);
        $alice = $this->createUser(['name' => 'Alice', 'client_id' => $client->id]);

        // Prior-month usage must not count toward this month's spend.
        TokenUsage::create([
            'session_id' => 'old-1', 'client_id' => $client->id,
            'user_id' => $alice->id, 'user_name' => $alice->name,
            'total_tokens' => 30_000_000, 'prompt_tokens' => 30_000_000, 'completion_tokens' => 0,
            'cost_usd' => 800.0, 'created_at' => now()->subMonth(),
        ]);

        $fresh = $this->actingAs($alice)->getJson('/api/client/usage');
        $fresh->assertOk();
        $fresh->assertJsonPath('budget.spend_usd', 0);
        $fresh->assertJsonPath('budget.remaining_usd', 50);

        // Current-month spend exceeding the limit clamps remaining to zero.
        TokenUsage::create([
            'session_id' => 'over-1', 'client_id' => $client->id,
            'user_id' => $alice->id, 'user_name' => $alice->name,
            'total_tokens' => 900_000_000, 'prompt_tokens' => 500_000_000, 'completion_tokens' => 400_000_000,
            'cost_usd' => 120.0, 'created_at' => now(),
        ]);

        $over = $this->actingAs($alice)->getJson('/api/client/usage');
        $over->assertOk();
        $this->assertSame(0, (int) $over->json('budget.remaining_usd'));
        $this->assertGreaterThanOrEqual(50, (float) $over->json('budget.spend_usd'));
    }

    public function test_client_usage_rejects_clientless_and_unauthenticated(): void
    {
        $this->createClient();

        $this->getJson('/api/client/usage')->assertStatus(401);

        $orphan = $this->createUser(['client_id' => null]);
        $this->actingAs($orphan)->getJson('/api/client/usage')->assertForbidden();
    }
}
