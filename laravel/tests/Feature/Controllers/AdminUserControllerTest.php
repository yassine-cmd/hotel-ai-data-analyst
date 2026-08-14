<?php

namespace Tests\Feature\Controllers;

use App\Models\Admin;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    private function actingAsAdmin(): void
    {
        $this->actingAs($this->createAdminUser(), 'admin');
    }

    public function test_store_creates_admin(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Ops Manager',
            'username' => 'ops-manager',
            'password' => 'secret123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('is_admin', true);
        $this->assertDatabaseHas('admins', ['username' => 'ops-manager']);
    }

    public function test_store_ignores_client_attachment_and_never_creates_user(): void
    {
        $this->actingAsAdmin();
        $client = $this->createClient();

        // A previously accepted "client_id" field must no longer create a client-scoped user.
        $response = $this->postJson('/api/admin/users', [
            'name' => 'Not Synced',
            'username' => 'not-synced',
            'password' => 'secret123',
            'client_id' => $client->id,
            'is_admin' => false,
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('users', ['username' => 'not-synced']);
    }

    public function test_index_returns_only_admins(): void
    {
        $this->actingAsAdmin();
        $this->createAdminUser();

        $response = $this->getJson('/api/admin/users');

        $response->assertOk();
        $this->assertNotEmpty($response->json());
        foreach ($response->json() as $item) {
            $this->assertSame(true, $item['is_admin']);
        }
    }

    public function test_update_updates_admin_name(): void
    {
        $this->actingAsAdmin();
        $admin = $this->createAdminUser(['name' => 'Old Name']);

        $response = $this->putJson("/api/admin/users/{$admin->id}", ['name' => 'New Name']);

        $response->assertOk();
        $this->assertSame('New Name', Admin::findOrFail($admin->id)->name);
    }

    public function test_destroy_deletes_admin(): void
    {
        $this->actingAsAdmin();
        $admin = $this->createAdminUser();

        $response = $this->deleteJson("/api/admin/users/{$admin->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('admins', ['id' => $admin->id]);
    }
}