<?php

namespace Tests\Feature\Controllers;

use App\Models\SessionMetadata;
use App\Models\TokenUsage;
use App\Models\User;
use App\Services\PythonProxyService;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    private User $user;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->createClient();
        $this->clientId = $client->id;
        $this->user = $this->createUser([
            'client_id' => $client->id,
        ]);
    }

    // --- index ---

    public function test_index_returns_sessions_from_db(): void
    {
        SessionMetadata::create([
            'session_id' => 'sess-1',
            'client_id' => $this->clientId,
            'name' => 'Session 1',
            'turn_count' => 3,
            'path' => "{$this->clientId}/sess-1/",
            'created_at' => now(),
            'last_access' => now(),
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson('/api/client/sessions');

        $response->assertOk();
        $response->assertJsonPath('sessions.0.session_id', 'sess-1');
    }

    public function test_index_returns_empty_when_none_exist(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/client/sessions');

        $response->assertOk();
        $response->assertJsonPath('sessions', []);
    }

    public function test_index_scopes_by_client(): void
    {
        SessionMetadata::create([
            'session_id' => 'my-sess',
            'client_id' => $this->clientId,
            'name' => 'Mine',
            'path' => "{$this->clientId}/my-sess/",
            'created_at' => now(),
            'last_access' => now(),
        ]);
        $other = $this->createClient();
        SessionMetadata::create([
            'session_id' => 'other-sess',
            'client_id' => $other->id,
            'name' => 'Not Mine',
            'path' => "{$other->id}/other-sess/",
            'created_at' => now(),
            'last_access' => now(),
        ]);

        $this->actingAs($this->user);

        $response = $this->getJson('/api/client/sessions');

        $response->assertOk();
        $response->assertJsonCount(1, 'sessions');
        $response->assertJsonPath('sessions.0.session_id', 'my-sess');
    }

    public function test_index_rejects_clientless_user(): void
    {
        $orphan = $this->createUser(['client_id' => null]);
        $this->actingAs($orphan);

        $response = $this->getJson('/api/client/sessions');

        $response->assertForbidden();
    }

    public function test_index_rejects_admin_without_client(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->getJson('/api/client/sessions');

        $response->assertForbidden();
    }

    public function test_index_rejects_unauthenticated(): void
    {
        $response = $this->getJson('/api/client/sessions');

        $response->assertStatus(401);
    }

    // --- store ---

    public function test_store_creates_session(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/client/sessions', ['name' => 'New Session']);

        $response->assertOk();
        $response->assertJsonPath('name', 'New Session');
        $this->assertNotNull($response->json('session_id'));

        $this->assertDatabaseHas('session_metadata', [
            'session_id' => $response->json('session_id'),
            'client_id' => $this->clientId,
            'name' => 'New Session',
        ]);
    }

    public function test_store_uses_provided_session_id(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/client/sessions', [
            'session_id' => 'custom-id',
            'name' => 'Custom',
        ]);

        $response->assertOk();
        $response->assertJsonPath('session_id', 'custom-id');

        $this->assertDatabaseHas('session_metadata', [
            'session_id' => 'custom-id',
            'client_id' => $this->clientId,
        ]);
    }

    public function test_store_rejects_admin_without_client(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/client/sessions', ['name' => 'Admin Session']);

        $response->assertForbidden();
    }

    public function test_store_rejects_unauthenticated(): void
    {
        $response = $this->postJson('/api/client/sessions', ['name' => 'Nope']);

        $response->assertStatus(401);
    }

    // --- update ---

    public function test_update_renames_session(): void
    {
        SessionMetadata::create([
            'session_id' => 'rename-sess',
            'client_id' => $this->clientId,
            'name' => 'Old Name',
            'path' => "{$this->clientId}/rename-sess/",
            'created_at' => now(),
            'last_access' => now(),
        ]);

        $this->actingAs($this->user);

        $response = $this->putJson('/api/client/sessions/rename-sess', ['name' => 'New Name']);

        $response->assertOk();

        $this->assertDatabaseHas('session_metadata', [
            'session_id' => 'rename-sess',
            'name' => 'New Name',
        ]);
    }

    public function test_update_returns_404_for_missing_session(): void
    {
        $this->actingAs($this->user);

        $response = $this->putJson('/api/client/sessions/nonexistent', ['name' => 'New Name']);

        $response->assertNotFound();
    }

    public function test_update_rejects_admin_without_client(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->putJson('/api/client/sessions/some-sess', ['name' => 'Admin Update']);

        $response->assertForbidden();
    }

    public function test_update_rejects_unauthenticated(): void
    {
        $response = $this->putJson('/api/client/sessions/some-sess', ['name' => 'Nope']);

        $response->assertStatus(401);
    }

    // --- destroy ---

    public function test_destroy_deletes_session(): void
    {
        SessionMetadata::create([
            'session_id' => 'del-sess',
            'client_id' => $this->clientId,
            'name' => 'To Delete',
            'path' => "{$this->clientId}/del-sess/",
            'created_at' => now(),
            'last_access' => now(),
        ]);

        TokenUsage::create([
            'session_id' => 'del-sess',
            'client_id' => $this->clientId,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'total_tokens' => 1200,
            'prompt_tokens' => 900,
            'completion_tokens' => 300,
            'reasoning_tokens' => 50,
            'created_at' => now(),
        ]);

        $this->actingAs($this->user);

        $mock = $this->mock(PythonProxyService::class);
        $mock->shouldReceive('forwardPost')
            ->once()
            ->with("/internal/sessions/{$this->clientId}/del-sess/cleanup")
            ->andReturn(['status' => 'ok']);

        $response = $this->deleteJson('/api/client/sessions/del-sess');

        $response->assertOk();
        $this->assertDatabaseMissing('session_metadata', ['session_id' => 'del-sess']);
        $this->assertDatabaseHas('token_usage', [
            'session_id' => 'del-sess',
            'total_tokens' => 1200,
        ]);
    }

    public function test_destroy_rejects_admin_without_client(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->deleteJson('/api/client/sessions/some-sess');

        $response->assertForbidden();
    }

    public function test_destroy_rejects_unauthenticated(): void
    {
        $response = $this->deleteJson('/api/client/sessions/some-sess');

        $response->assertStatus(401);
    }

    // --- history ---

    public function test_history_returns_conversation(): void
    {
        $this->actingAs($this->user);

        $mock = $this->mock(PythonProxyService::class);
        $mock->shouldReceive('forwardGet')
            ->once()
            ->with("/internal/sessions/{$this->clientId}/hist-sess/history")
            ->andReturn(['history' => [['role' => 'user', 'content' => 'Hello']]]);

        $response = $this->getJson('/api/client/sessions/hist-sess/history');

        $response->assertOk();
        $response->assertJsonPath('history.0.content', 'Hello');
    }

    public function test_history_rejects_admin_without_client(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->getJson('/api/client/sessions/some-sess/history');

        $response->assertForbidden();
    }

    public function test_history_rejects_unauthenticated(): void
    {
        $response = $this->getJson('/api/client/sessions/some-sess/history');

        $response->assertStatus(401);
    }
}
