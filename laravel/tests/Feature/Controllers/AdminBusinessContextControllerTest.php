<?php

namespace Tests\Feature\Controllers;

use App\Models\BusinessContext;
use App\Models\SchemaMetadata;
use Tests\TestCase;

class AdminBusinessContextControllerTest extends TestCase
{
    private function actingAsAdmin(): void
    {
        $this->actingAs($this->createAdminUser(), 'admin');
    }

    private function tableMetadata(string $table): SchemaMetadata
    {
        return SchemaMetadata::create([
            'metadata_type' => 'table',
            'table_name' => $table,
            'is_sensitive' => false,
            'is_archived' => false,
        ]);
    }

    private function columnMetadata(string $table, string $column): SchemaMetadata
    {
        return SchemaMetadata::create([
            'metadata_type' => 'column',
            'table_name' => $table,
            'column_name' => $column,
            'data_type' => 'varchar',
            'is_sensitive' => false,
            'is_archived' => false,
        ]);
    }

    public function test_index_returns_all_entries_ordered_by_title(): void
    {
        $this->actingAsAdmin();
        BusinessContext::create(['title' => 'Zulu', 'content' => 'A']);
        BusinessContext::create(['title' => 'Alpha', 'content' => 'B']);

        $response = $this->getJson('/api/admin/business-context');

        $response->assertOk();
        $response->assertJsonCount(2);
        $this->assertSame('Alpha', $response->json('0.title'));
    }

    public function test_index_filters_by_scope_and_active(): void
    {
        $this->actingAsAdmin();
        BusinessContext::create(['title' => 'Scoped', 'content' => 'A', 'scope_table' => 'reservations']);
        BusinessContext::create(['title' => 'General', 'content' => 'B', 'is_active' => false]);

        $this->getJson('/api/admin/business-context?scope=reservations')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Scoped');

        $this->getJson('/api/admin/business-context?active=1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Scoped');

        $this->getJson('/api/admin/business-context?active=0')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'General');
    }

    public function test_store_creates_entry(): void
    {
        $this->actingAsAdmin();
        $this->tableMetadata('reservations');
        $this->columnMetadata('reservations', 'status');

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'No-show policy',
            'content' => 'A reservation is stale if check-in is over 30 days in the past.',
            'scope_table' => 'reservations',
            'scope_column' => 'status',
            'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'No-show policy');
        $this->assertDatabaseHas('business_context', ['title' => 'No-show policy', 'scope_table' => 'reservations']);
    }

    public function test_store_creates_unscoped_note(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Langue des clients',
            'content' => 'Tous les libellés clients sont en français.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('scope_table', null);
    }

    public function test_store_rejects_content_over_limit(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Huge',
            'content' => str_repeat('x', 5001),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    public function test_store_rejects_global_total_over_limit(): void
    {
        $this->actingAsAdmin();
        BusinessContext::create(['title' => 'Base', 'content' => str_repeat('x', 5500)]);

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Extra',
            'content' => str_repeat('y', 600),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content']);
    }

    public function test_store_allows_entries_within_global_total(): void
    {
        $this->actingAsAdmin();
        BusinessContext::create(['title' => 'Base', 'content' => str_repeat('x', 5500)]);

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Small',
            'content' => 'Fits under the budget.',
        ]);

        $response->assertCreated();
    }

    public function test_store_ignores_inactive_and_scoped_for_global_total(): void
    {
        $this->actingAsAdmin();
        $this->tableMetadata('reservations');
        $this->columnMetadata('reservations', 'status');
        BusinessContext::create(['title' => 'Inactive', 'content' => str_repeat('x', 5500), 'is_active' => false]);
        BusinessContext::create(['title' => 'Base', 'content' => str_repeat('x', 5500)]);

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Scoped rule',
            'content' => str_repeat('z', 4900),
            'scope_table' => 'reservations',
            'scope_column' => 'status',
        ]);

        $response->assertCreated();
    }

    public function test_update_excludes_self_from_global_total(): void
    {
        $this->actingAsAdmin();
        $entry = BusinessContext::create(['title' => 'Base', 'content' => str_repeat('x', 4900)]);

        $response = $this->putJson("/api/admin/business-context/{$entry->id}", [
            'title' => 'Base',
            'content' => str_repeat('x', 4900),
        ]);

        $response->assertOk();
    }

    public function test_content_max_is_configurable(): void
    {
        $this->actingAsAdmin();

        // Default content_max=5000 rejects a 5500-char entry.
        $this->postJson('/api/admin/business-context', [
            'title' => 'Big',
            'content' => str_repeat('x', 5500),
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);

        // Raised content_max accepts it (still within the fixed 6000 total).
        config()->set('business_context.content_max', 6000);
        $this->postJson('/api/admin/business-context', [
            'title' => 'Big',
            'content' => str_repeat('x', 5500),
        ])->assertCreated();

        // And still rejects above the new per-entry cap.
        $this->postJson('/api/admin/business-context', [
            'title' => 'Huge',
            'content' => str_repeat('x', 6050),
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);
    }

    public function test_title_max_is_configurable(): void
    {
        config()->set('business_context.title_max', 100);
        $this->actingAsAdmin();

        $this->postJson('/api/admin/business-context', [
            'title' => str_repeat('t', 90),
            'content' => 'ok',
        ])->assertCreated();

        $this->postJson('/api/admin/business-context', [
            'title' => str_repeat('t', 120),
            'content' => 'too long',
        ])->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_config_endpoint_returns_limits(): void
    {
        config()->set('business_context.title_max', 100);
        config()->set('business_context.content_max', 7000);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/business-context/config')
            ->assertOk()
            ->assertExactJson([
                'title_max' => 100,
                'content_max' => 7000,
                'total_max' => 6000,
            ]);
    }

    public function test_config_endpoint_rejects_non_admin(): void
    {
        $this->actingAs($this->createUser());
        $this->getJson('/api/admin/business-context/config')->assertForbidden();
    }

    public function test_config_endpoint_rejects_unauthenticated(): void
    {
        $this->getJson('/api/admin/business-context/config')->assertStatus(401);
    }

    public function test_store_rejects_duplicate_title(): void
    {
        $this->actingAsAdmin();
        BusinessContext::create(['title' => 'No-show policy', 'content' => 'First']);

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'No-show policy',
            'content' => 'Duplicate',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/business-context', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'content']);
    }

    public function test_store_rejects_unknown_scope_table(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Rule',
            'content' => 'Body',
            'scope_table' => 'does_not_exist',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_table']);
    }

    public function test_store_rejects_scope_column_without_scope_table(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Rule',
            'content' => 'Body',
            'scope_column' => 'status',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_column']);
    }

    public function test_store_rejects_scope_column_not_in_table(): void
    {
        $this->actingAsAdmin();
        $this->tableMetadata('reservations');

        $response = $this->postJson('/api/admin/business-context', [
            'title' => 'Rule',
            'content' => 'Body',
            'scope_table' => 'reservations',
            'scope_column' => 'nope',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_column']);
    }

    public function test_show_returns_entry(): void
    {
        $this->actingAsAdmin();
        $entry = BusinessContext::create(['title' => 'ADR', 'content' => 'Average Daily Rate']);

        $response = $this->getJson("/api/admin/business-context/{$entry->id}");

        $response->assertOk();
        $response->assertJsonPath('title', 'ADR');
    }

    public function test_show_returns_404_for_missing(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/business-context/99999')->assertNotFound();
    }

    public function test_update_modifies_entry(): void
    {
        $this->actingAsAdmin();
        $entry = BusinessContext::create(['title' => 'ADR', 'content' => 'Old']);

        $response = $this->putJson("/api/admin/business-context/{$entry->id}", [
            'title' => 'ADR',
            'content' => 'Average Daily Rate — updated',
            'is_active' => false,
        ]);

        $response->assertOk();
        $entry->refresh();
        $this->assertSame('Average Daily Rate — updated', $entry->content);
        $this->assertFalse($entry->is_active);
    }

    public function test_update_rejects_duplicate_title(): void
    {
        $this->actingAsAdmin();
        BusinessContext::create(['title' => 'ADR', 'content' => 'First']);
        $entry = BusinessContext::create(['title' => 'RevPAR', 'content' => 'Second']);

        $response = $this->putJson("/api/admin/business-context/{$entry->id}", [
            'title' => 'ADR',
            'content' => 'Trying to collide',
        ]);

        $response->assertStatus(422);
    }

    public function test_destroy_deletes_entry(): void
    {
        $this->actingAsAdmin();
        $entry = BusinessContext::create(['title' => 'Todelete', 'content' => 'Bye']);

        $response = $this->deleteJson("/api/admin/business-context/{$entry->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('business_context', ['id' => $entry->id]);
    }

    public function test_admin_routes_reject_non_admin(): void
    {
        $clientUser = $this->createUser();
        $this->actingAs($clientUser);

        $this->getJson('/api/admin/business-context')->assertForbidden();
        $this->postJson('/api/admin/business-context', [])->assertForbidden();
        $this->getJson('/api/admin/business-context/1')->assertForbidden();
        $this->putJson('/api/admin/business-context/1', [])->assertForbidden();
        $this->deleteJson('/api/admin/business-context/1')->assertForbidden();
    }

    public function test_admin_routes_reject_unauthenticated(): void
    {
        $this->getJson('/api/admin/business-context')->assertStatus(401);
        $this->postJson('/api/admin/business-context', [])->assertStatus(401);
        $this->getJson('/api/admin/business-context/1')->assertStatus(401);
        $this->putJson('/api/admin/business-context/1', [])->assertStatus(401);
        $this->deleteJson('/api/admin/business-context/1')->assertStatus(401);
    }
}
