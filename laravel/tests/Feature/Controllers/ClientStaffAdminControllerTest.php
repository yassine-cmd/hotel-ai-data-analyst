<?php

namespace Tests\Feature\Controllers;

use App\Models\Client;
use App\Models\PermissionToken;
use App\Models\User;
use Tests\TestCase;

class ClientStaffAdminControllerTest extends TestCase
{
    private Client $client;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createClient();
        $this->admin = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 1, 'permissions' => ['full_access']],
        ]);
        $this->staff = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 0, 'permissions' => ['analytics.view']],
        ]);
    }

    public function test_index_lists_role_zero_staff_of_own_client(): void
    {
        $this->actingAs($this->admin);

        $response = $this->getJson('/api/client/staff?page=1&per_page=10');

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.id', $this->staff->id);
        $response->assertJsonPath('users.0.permissions.0.code', 'analytics.view');
        $response->assertJsonPath('users.0.deactivated_at', null);
    }

    public function test_index_excludes_self_and_other_client_staff(): void
    {
        $otherClient = $this->createClient(['name' => 'Other Hotel']);
        $otherStaff = $this->createUser([
            'client_id' => $otherClient->id,
            'permissions' => ['role' => 0, 'permissions' => []],
        ]);
        $this->actingAs($this->admin);

        $response = $this->getJson('/api/client/staff');

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.id', $this->staff->id);
    }

    public function test_index_resolves_permission_badges(): void
    {
        $token = PermissionToken::create([
            'code' => 'analytics.view',
            'name' => 'View Analytics',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $response = $this->getJson('/api/client/staff');

        $response->assertOk();
        $response->assertJsonPath('users.0.permissions.0.name', 'View Analytics');
        $this->assertDatabaseHas('permission_tokens', ['id' => $token->id]);
    }

    public function test_deactivate_and_activate(): void
    {
        $this->actingAs($this->admin);

        $this->postJson("/api/client/staff/{$this->staff->id}/deactivate")->assertOk();
        $this->assertNotNull($this->staff->fresh()->deactivated_at);

        $this->postJson("/api/client/staff/{$this->staff->id}/activate")->assertOk();
        $this->assertNull($this->staff->fresh()->deactivated_at);
    }

    public function test_deactivate_rejects_role_one_target(): void
    {
        $roleOneUser = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 1, 'permissions' => ['full_access']],
        ]);
        $this->actingAs($this->admin);

        $response = $this->postJson("/api/client/staff/{$roleOneUser->id}/deactivate");

        $response->assertForbidden();
        $this->assertNull($roleOneUser->fresh()->deactivated_at);
    }

    public function test_deactivate_rejects_other_client_user(): void
    {
        $otherClient = $this->createClient(['name' => 'Other Hotel']);
        $otherStaff = $this->createUser([
            'client_id' => $otherClient->id,
            'permissions' => ['role' => 0, 'permissions' => []],
        ]);
        $this->actingAs($this->admin);

        $response = $this->postJson("/api/client/staff/{$otherStaff->id}/deactivate");

        $response->assertNotFound();
    }

    public function test_index_rejects_non_admin_role(): void
    {
        $this->actingAs($this->staff);

        $response = $this->getJson('/api/client/staff');

        $response->assertForbidden();
    }

    public function test_index_rejects_system_admin(): void
    {
        $this->actingAs($this->createAdminUser());

        $response = $this->getJson('/api/client/staff');

        $response->assertForbidden();
    }

    public function test_index_rejects_unauthenticated(): void
    {
        $response = $this->getJson('/api/client/staff');

        $response->assertStatus(401);
    }

    // --- index search / status filters ---

    public function test_index_search_filters_by_name_username_or_department(): void
    {
        $this->actingAs($this->admin);
        $this->createUser(['client_id' => $this->client->id, 'name' => 'Alice Dupont', 'permissions' => ['role' => 0, 'permissions' => []]]);
        $this->createUser(['client_id' => $this->client->id, 'name' => 'Bob Martin', 'department' => 'Housekeeping', 'permissions' => ['role' => 0, 'permissions' => []]]);

        $response = $this->getJson('/api/client/staff?q=alice');
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Alice Dupont');

        $response = $this->getJson('/api/client/staff?q=housekeeping');
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Bob Martin');
    }

    public function test_index_status_filter_isolates_active_and_deactivated(): void
    {
        $this->actingAs($this->admin);
        $active = $this->createUser(['client_id' => $this->client->id, 'name' => 'Active Worker', 'permissions' => ['role' => 0, 'permissions' => []]]);
        $this->createUser(['client_id' => $this->client->id, 'name' => 'Gone Worker', 'permissions' => ['role' => 0, 'permissions' => []], 'deactivated_at' => now()]);

        $response = $this->getJson('/api/client/staff?status=active&q=Active+Worker');
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.id', $active->id);

        $response = $this->getJson('/api/client/staff?status=deactivated');
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Gone Worker');
    }
}
