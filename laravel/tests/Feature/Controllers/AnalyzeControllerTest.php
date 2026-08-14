<?php

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PythonProxyService;
use Tests\TestCase;

class AnalyzeControllerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->createClient();
        $this->user = $this->createUser([
            'client_id' => $client->id,
        ]);
    }

    public function test_analyze_calls_proxy_with_correct_args(): void
    {
        $this->actingAs($this->user);

        $mock = $this->mock(PythonProxyService::class);
        $mock->shouldReceive('analyze')
            ->once()
            ->with(
                'Show me revenue',
                \Mockery::type('string'),
                \Mockery::on(fn($c) => $c->id === $this->user->client_id),
                $this->user->id,
                $this->user->name
            )
            ->andReturn(response()->stream(function () { echo '{}'; }));

        $response = $this->postJson('/api/analyze', ['query' => 'Show me revenue']);

        $response->assertOk();
    }

    public function test_analyze_validates_missing_query(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/analyze', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['query']);
    }

    public function test_analyze_rejects_clientless_user(): void
    {
        $orphan = $this->createUser(['client_id' => null]);
        $this->actingAs($orphan);

        $response = $this->postJson('/api/analyze', ['query' => 'test']);

        $response->assertForbidden();
    }

    public function test_analyze_rejects_admin_without_client(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/analyze', ['query' => 'test']);

        $response->assertForbidden();
    }

    public function test_analyze_rejects_deactivated_client(): void
    {
        $client = $this->user->client;
        $client->is_active = false;
        $client->deactivated_at = now();
        $client->save();
        $this->actingAs($this->user);

        $response = $this->postJson('/api/analyze', ['query' => 'test']);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Your account has been deactivated. Contact an administrator.');
    }

    public function test_analyze_rejects_client_over_budget(): void
    {
        $client = $this->user->client;
        $client->budget_limit_usd = 0;
        $client->save();
        $this->actingAs($this->user);

        $response = $this->postJson('/api/analyze', ['query' => 'test']);

        $response->assertStatus(429);
    }

    public function test_analyze_allows_client_within_budget(): void
    {
        $client = $this->user->client;
        $client->budget_limit_usd = 100;
        $client->save();
        $this->actingAs($this->user);

        $mock = $this->mock(PythonProxyService::class);
        $mock->shouldReceive('analyze')
            ->once()
            ->andReturn(response()->stream(function () { echo '{}'; }));

        $response = $this->postJson('/api/analyze', ['query' => 'revenue']);

        $response->assertOk();
    }

    public function test_analyze_rejects_unauthenticated(): void
    {
        $response = $this->postJson('/api/analyze', ['query' => 'test']);

        $response->assertStatus(401);
    }

    public function test_analyze_passes_session_id(): void
    {
        $this->actingAs($this->user);

        $mock = $this->mock(PythonProxyService::class);
        $mock->shouldReceive('analyze')
            ->once()
            ->with('revenue', 'custom-sess-id', \Mockery::type('object'), $this->user->id, $this->user->name)
            ->andReturn(response()->stream(function () { echo '{}'; }));

        $response = $this->postJson('/api/analyze', [
            'query' => 'revenue',
            'session_id' => 'custom-sess-id',
        ]);

        $response->assertOk();
    }
}
