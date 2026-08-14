<?php

namespace Tests\Feature\Controllers;

use App\Models\Admin;
use App\Models\Client;
use App\Models\User;
use App\Services\HotelUserSyncService;
use App\Services\ReadOnlyUserService;
use Tests\TestCase;

class AdminClientControllerTest extends TestCase
{
    private function actingAsAdmin(): Admin
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'admin');
        return $admin;
    }

    // --- index ---

    public function test_index_returns_all_clients(): void
    {
        $this->actingAsAdmin();
        $this->createClient(['name' => 'Hotel A']);
        $this->createClient(['name' => 'Hotel B']);

        $response = $this->getJson('/api/admin/clients');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    // --- show ---

    // --- index search / status filters ---

    public function test_index_search_filters_by_name_or_dsn(): void
    {
        $this->actingAsAdmin();
        $this->createClient(['name' => 'Alpha Hotel', 'analytics_db_dsn' => 'host1:3306/alpha']);
        $this->createClient(['name' => 'Beta Hotel', 'analytics_db_dsn' => 'host2:3306/beta']);

        $response = $this->getJson('/api/admin/clients?q=alpha');
        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Alpha Hotel');

        $response = $this->getJson('/api/admin/clients?q=host2');
        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Beta Hotel');
    }

    public function test_index_status_filter_isolates_active_and_deactivated(): void
    {
        $this->actingAsAdmin();
        $this->createClient(['name' => 'Active Hotel', 'is_active' => true]);
        $this->createClient(['name' => 'Gone Hotel', 'is_active' => false]);

        $response = $this->getJson('/api/admin/clients?status=active');
        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Active Hotel');

        $response = $this->getJson('/api/admin/clients?status=deactivated');
        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Gone Hotel');
    }

    public function test_show_returns_client(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->getJson("/api/admin/clients/{$client->id}");

        $response->assertOk();
        $response->assertJsonPath('name', 'Test Hotel');
    }

    public function test_show_returns_404_for_missing_client(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/clients/99999');

        $response->assertNotFound();
    }

    // --- store ---

    public function test_store_creates_client(): void
    {
        $this->actingAsAdmin();

        $mockService = $this->mock(ReadOnlyUserService::class);
        $mockService->shouldReceive('usernameForClient')
            ->once()
            ->andReturn('fms_newhotel_aabb1122');

        $response = $this->postJson('/api/admin/clients', [
            'name' => 'New Hotel',
            'analytics_db_dsn' => 'db-host:3307/new_hotel_db',
            'analytics_admin_user' => 'root',
            'analytics_admin_password' => 'adminpass',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('client.name', 'New Hotel');

        $this->assertDatabaseHas('clients', ['name' => 'New Hotel']);
    }

    public function test_store_uses_deterministic_agent_username(): void
    {
        $this->actingAsAdmin();

        $mockService = $this->mock(ReadOnlyUserService::class);
        $mockService->shouldReceive('usernameForClient')
            ->once()
            ->andReturn('fms_provisioned_aabb1122');

        $response = $this->postJson('/api/admin/clients', [
            'name' => 'Provisioned Hotel',
            'analytics_db_dsn' => 'db-host:3307/prod_db',
            'analytics_admin_user' => 'root',
            'analytics_admin_password' => 'adminpass',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('client.name', 'Provisioned Hotel');

        $client = Client::where('name', 'Provisioned Hotel')->first();
        $this->assertNotNull($client);
        $this->assertMatchesRegularExpression('/^fms_[a-z0-9]{1,12}_[a-f0-9]{8}$/', $client->analytics_agent_user);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/clients', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'analytics_db_dsn', 'analytics_admin_user']);
    }

    public function test_store_accepts_empty_admin_password(): void
    {
        $this->actingAsAdmin();

        $mockService = $this->mock(ReadOnlyUserService::class);
        $mockService->shouldReceive('usernameForClient')
            ->once()
            ->andReturn('fms_nopass_aabb1122');

        $response = $this->postJson('/api/admin/clients', [
            'name' => 'No Password Hotel',
            'analytics_db_dsn' => 'localhost:3306/hotel',
            'analytics_admin_user' => 'root',
        ]);

        $response->assertCreated();

        $client = Client::where('name', 'No Password Hotel')->first();
        $this->assertNotNull($client);
        $this->assertSame('', $client->decrypted_admin_password);
    }

    public function test_test_connection_allows_empty_password(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/clients/test-connection', [
            'dsn' => '127.0.0.1:1/nonexistent_db',
            'username' => 'root',
            'password' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
    }

    public function test_test_connection_accepts_full_url_dsn(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/clients/test-connection', [
            'dsn' => 'mysql://root@127.0.0.1:1/nonexistent_db',
            'username' => 'root',
            'password' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
    }

    // --- update ---

    public function test_update_updates_client_fields(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient(['name' => 'Old Name']);

        $response = $this->putJson("/api/admin/clients/{$client->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'New Name']);
    }

    public function test_update_encrypts_new_admin_password(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->putJson("/api/admin/clients/{$client->id}", [
            'analytics_admin_password' => 'raw-password',
        ]);

        $response->assertOk();
        $client->refresh();
        $this->assertNotSame('raw-password', $client->analytics_admin_password);
        $this->assertSame('raw-password', $client->decrypted_admin_password);
    }

    // --- destroy ---

    public function test_destroy_deletes_client_and_users(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $this->createUser(['client_id' => $client->id]);

        $response = $this->deleteJson("/api/admin/clients/{$client->id}", ['password' => 'password']);

        $response->assertOk();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertDatabaseMissing('users', ['client_id' => $client->id]);
    }

    public function test_destroy_with_deprovisioning(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $mockService = $this->mock(ReadOnlyUserService::class);
        $mockService->shouldReceive('deprovisionFromClient')
            ->once();

        $response = $this->deleteJson("/api/admin/clients/{$client->id}", ['password' => 'password']);

        $response->assertOk();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_destroy_handles_deprovision_error_gracefully(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $mockService = $this->mock(ReadOnlyUserService::class);
        $mockService->shouldReceive('deprovisionFromClient')
            ->andThrow(new \RuntimeException('Connection lost'));

        $response = $this->deleteJson("/api/admin/clients/{$client->id}", ['password' => 'password']);

        $response->assertOk();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_destroy_requires_admin_password(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->deleteJson("/api/admin/clients/{$client->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_destroy_rejects_wrong_password(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->deleteJson("/api/admin/clients/{$client->id}", ['password' => 'wrong-password']);

        $response->assertForbidden();
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    // --- deactivate / reactivate ---

    public function test_deactivate_soft_deletes_client(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $this->createUser(['client_id' => $client->id]);

        $response = $this->postJson("/api/admin/clients/{$client->id}/deactivate");

        $response->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'is_active' => false]);
        $this->assertNotNull($client->fresh()->deactivated_at);
        $this->assertDatabaseHas('users', ['client_id' => $client->id]);
    }

    public function test_reactivate_restores_client(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $client->is_active = false;
        $client->deactivated_at = now();
        $client->save();

        $response = $this->postJson("/api/admin/clients/{$client->id}/reactivate");

        $response->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'is_active' => true]);
        $this->assertNull($client->fresh()->deactivated_at);
    }

    public function test_update_sets_budget(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $response = $this->putJson("/api/admin/clients/{$client->id}", [
            'budget_limit_usd' => 100.50,
        ]);

        $response->assertOk();
        $this->assertSame(100.50, (float) $client->fresh()->budget_limit_usd);
    }

    public function test_update_clears_budget_with_null(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient(['budget_limit_usd' => 50]);

        $response = $this->putJson("/api/admin/clients/{$client->id}", [
            'budget_limit_usd' => null,
        ]);

        $response->assertOk();
        $this->assertNull($client->fresh()->budget_limit_usd);
    }

    // --- auth guards ---

    public function test_admin_routes_reject_non_admin(): void
    {
        $clientUser = $this->createUser();
        $this->actingAs($clientUser);

        $this->getJson('/api/admin/clients')->assertForbidden();
        $this->postJson('/api/admin/clients', [])->assertForbidden();
        $this->getJson('/api/admin/clients/1')->assertForbidden();
        $this->putJson('/api/admin/clients/1', [])->assertForbidden();
        $this->deleteJson('/api/admin/clients/1')->assertForbidden();
    }

    public function test_admin_routes_reject_unauthenticated(): void
    {
        $this->getJson('/api/admin/clients')->assertStatus(401);
        $this->postJson('/api/admin/clients', [])->assertStatus(401);
        $this->getJson('/api/admin/clients/1')->assertStatus(401);
        $this->putJson('/api/admin/clients/1', [])->assertStatus(401);
        $this->deleteJson('/api/admin/clients/1')->assertStatus(401);
    }

    // --- user discover / sync ---

    public function test_discover_users_returns_summary_and_rows(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $this->mock(HotelUserSyncService::class)
            ->shouldReceive('discover')
            ->once()
            ->andReturn([
                'summary' => ['users_live' => 1, 'users_local' => 0, 'new' => 1, 'changed' => 0, 'synced' => 0, 'conflicts' => 0],
                'rows' => [[
                    'external_id' => 3,
                    'username' => 'admin',
                    'name' => 'Admin Hotel',
                    'department' => 'Reception',
                    'permissions' => ['role' => 1, 'permissions' => []],
                    'is_new' => true,
                    'is_changed' => false,
                    'status' => 'new',
                ]],
            ]);

        $response = $this->getJson("/api/admin/clients/{$client->id}/users/discover");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.summary.new', 1);
        $response->assertJsonCount(1, 'data.users');
        $response->assertJsonPath('data.users.0.status', 'new');
    }

    public function test_discover_users_returns_409_on_failure(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $this->mock(HotelUserSyncService::class)
            ->shouldReceive('discover')
            ->once()
            ->andThrow(new \RuntimeException('Could not connect to the hotel database.'));

        $response = $this->getJson("/api/admin/clients/{$client->id}/users/discover");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'USER_DISCOVERY_FAILED');
    }

    public function test_sync_users_applies_and_returns_summary(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $this->mock(HotelUserSyncService::class)
            ->shouldReceive('sync')
            ->once()
            ->andReturn([
                'summary' => ['seen' => 1, 'created' => 1, 'updated' => 0, 'adopted' => 0, 'synced' => 0, 'conflicts' => 0],
                'rows' => [[
                    'external_id' => 3,
                    'username' => 'admin',
                    'name' => 'Admin Hotel',
                    'department' => 'Reception',
                    'permissions' => ['role' => 1, 'permissions' => []],
                    'status' => 'created',
                    'user_id' => 5,
                ]],
            ]);

        $response = $this->postJson("/api/admin/clients/{$client->id}/users/sync");

        $response->assertOk();
        $response->assertJsonPath('data.summary.created', 1);
        $response->assertJsonPath('data.users.0.status', 'created');
        $response->assertJsonPath('data.users.0.user_id', 5);
    }

    public function test_sync_users_returns_409_on_failure(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $this->mock(HotelUserSyncService::class)
            ->shouldReceive('sync')
            ->once()
            ->andThrow(new \RuntimeException('A user sync run is already in progress.'));

        $response = $this->postJson("/api/admin/clients/{$client->id}/users/sync");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'USER_SYNC_FAILED');
    }

    public function test_discover_users_returns_404_for_missing_client(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/clients/99999/users/discover');

        $response->assertNotFound();
    }

    public function test_user_sync_routes_reject_non_admin(): void
    {
        $clientUser = $this->createUser();
        $this->actingAs($clientUser);

        $this->getJson('/api/admin/clients/1/users/discover')->assertForbidden();
        $this->postJson('/api/admin/clients/1/users/sync')->assertForbidden();
    }

    // --- dashboard user role exposure ---

    public function test_dashboard_exposes_user_role_and_deactivated_at(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $adminUser = $this->createUser([
            'client_id' => $client->id,
            'name' => 'Admin Person',
            'permissions' => ['role' => 1, 'permissions' => ['full_access']],
        ]);
        $staffUser = $this->createUser([
            'client_id' => $client->id,
            'name' => 'Staff Person',
            'permissions' => ['role' => 0, 'permissions' => ['RESERVATION']],
            'deactivated_at' => now(),
        ]);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard");

        $response->assertOk();
        $response->assertJsonPath('users.0.id', $adminUser->id);
        $response->assertJsonPath('users.0.role', 1);
        $response->assertJsonPath('users.0.deactivated_at', null);
        $response->assertJsonPath('users.1.id', $staffUser->id);
        $response->assertJsonPath('users.1.role', 0);
        $this->assertNotNull($response->json('users.1.deactivated_at'));
    }

    public function test_dashboard_exposes_public_key(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient(['public_key' => '966893331286eedc9a450c66780d991c2debf996e92abf777c5879c6c227c57b']);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard");

        $response->assertOk();
        $response->assertJsonPath('client.public_key', '966893331286eedc9a450c66780d991c2debf996e92abf777c5879c6c227c57b');
    }

    public function test_dashboard_public_key_is_null_when_unset(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient(['public_key' => null]);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard");

        $response->assertOk();
        $response->assertJsonPath('client.public_key', null);
    }

    // --- system-admin user activate/deactivate ---

    public function test_deactivate_user_sets_deactivated_at(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $user = $this->createUser(['client_id' => $client->id]);

        $response = $this->postJson("/api/admin/clients/{$client->id}/users/{$user->id}/deactivate");

        $response->assertOk();
        $this->assertNotNull($user->fresh()->deactivated_at);
        $response->assertJsonPath('id', $user->id);
    }

    public function test_activate_user_clears_deactivated_at(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $user = $this->createUser(['client_id' => $client->id, 'deactivated_at' => now()]);

        $response = $this->postJson("/api/admin/clients/{$client->id}/users/{$user->id}/activate");

        $response->assertOk();
        $this->assertNull($user->fresh()->deactivated_at);
        $response->assertJsonPath('deactivated_at', null);
    }

    public function test_deactivate_user_rejects_user_from_other_client(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $otherClient = $this->createClient(['name' => 'Other Hotel']);
        $foreignUser = $this->createUser(['client_id' => $otherClient->id]);

        $response = $this->postJson("/api/admin/clients/{$client->id}/users/{$foreignUser->id}/deactivate");

        $response->assertNotFound();
        $this->assertNull($foreignUser->fresh()->deactivated_at);
    }

    public function test_deactivate_user_returns_404_for_missing_client_or_user(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        $this->postJson('/api/admin/clients/99999/users/1/deactivate')->assertNotFound();
        $this->postJson("/api/admin/clients/{$client->id}/users/99999/deactivate")->assertNotFound();
    }

    public function test_user_access_routes_reject_non_admin(): void
    {
        $client = $this->createClient();
        $staff = $this->createUser(['client_id' => $client->id]);
        $this->actingAs($staff);

        $this->postJson("/api/admin/clients/{$client->id}/users/{$staff->id}/deactivate")->assertForbidden();
        $this->postJson("/api/admin/clients/{$client->id}/users/{$staff->id}/activate")->assertForbidden();
    }

    // --- dashboard search / role / status filters ---

    public function test_dashboard_search_filters_by_name_username_or_department(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $this->createUser(['client_id' => $client->id, 'name' => 'Alice Dupont']);
        $this->createUser(['client_id' => $client->id, 'name' => 'Bob Martin', 'department' => 'Reception']);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard?q=alice");

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Alice Dupont');

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard?q=reception");

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Bob Martin');
    }

    public function test_dashboard_role_filter_isolates_admins_and_staff(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $adminUser = $this->createUser(['client_id' => $client->id, 'name' => 'Boss', 'permissions' => ['role' => 1, 'permissions' => ['full_access']]]);
        $this->createUser(['client_id' => $client->id, 'name' => 'Worker', 'permissions' => ['role' => 0, 'permissions' => ['RESERVATION']]]);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard?role=1");
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.id', $adminUser->id);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard?role=0");
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Worker');
    }

    public function test_dashboard_status_filter_isolates_active_and_deactivated(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();
        $active = $this->createUser(['client_id' => $client->id, 'name' => 'Active Person']);
        $this->createUser(['client_id' => $client->id, 'name' => 'Gone Person', 'deactivated_at' => now()]);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard?status=active");
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.id', $active->id);

        $response = $this->getJson("/api/admin/clients/{$client->id}/dashboard?status=deactivated");
        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.name', 'Gone Person');
    }
}
