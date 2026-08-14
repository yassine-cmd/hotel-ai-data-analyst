<?php

namespace Tests\Feature\Controllers;

use App\Models\SchemaMetadata;
use Tests\TestCase;

class AdminSchemaControllerTest extends TestCase
{
    private function actingAsAdmin(): void
    {
        $this->actingAs($this->createAdminUser(), 'admin');
    }

    private function tableRow(string $table, array $overrides = []): SchemaMetadata
    {
        return SchemaMetadata::create(array_merge([
            'metadata_type' => 'table',
            'table_name' => $table,
            'column_name' => null,
            'description' => 'Auto description',
            'description_source' => 'auto',
        ], $overrides));
    }

    private function columnRow(string $table, string $column, array $overrides = []): SchemaMetadata
    {
        return SchemaMetadata::create(array_merge([
            'metadata_type' => 'column',
            'table_name' => $table,
            'column_name' => $column,
            'description' => null,
            'description_source' => 'none',
        ], $overrides));
    }

    public function test_import_updates_auto_sourced_row(): void
    {
        $this->actingAsAdmin();
        $row = $this->tableRow('reservations');

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => [
                ['table' => 'reservations', 'column' => null, 'description' => 'Booking records.'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('updated', 1);
        $row->refresh();
        $this->assertSame('Booking records.', $row->description);
        $this->assertSame('manual', $row->description_source);
        $this->assertSame(2, $row->row_version);
    }

    public function test_import_skips_manual_sourced_row_without_force(): void
    {
        $this->actingAsAdmin();
        $row = $this->tableRow('reservations', [
            'description' => 'Curated description',
            'description_source' => 'manual',
        ]);

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => [
                ['table' => 'reservations', 'column' => null, 'description' => 'AI description'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('skipped', 1);
        $this->assertSame('Curated description', $row->fresh()->description);
    }

    public function test_import_with_force_overwrites_manual_row(): void
    {
        $this->actingAsAdmin();
        $row = $this->tableRow('reservations', [
            'description' => 'Curated description',
            'description_source' => 'manual',
        ]);

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => [
                ['table' => 'reservations', 'column' => null, 'description' => 'AI description'],
            ],
            'force' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('updated', 1);
        $this->assertSame('AI description', $row->fresh()->description);
    }

    public function test_import_unknown_table_counts_as_not_found(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => [
                ['table' => 'missing_table', 'column' => null, 'description' => 'Anything'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('not_found', 1);
    }

    public function test_import_accepts_wrapper_object(): void
    {
        $this->actingAsAdmin();
        $this->tableRow('reservations');

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => [
                'descriptions' => [
                    ['table' => 'reservations', 'column' => null, 'description' => 'Booking records.'],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('updated', 1);
    }

    public function test_import_accepts_dot_notation(): void
    {
        $this->actingAsAdmin();
        $this->columnRow('reservations', 'status');

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => [
                ['table' => 'reservations.status', 'description' => 'Booking state.'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('updated', 1);
        $this->assertSame(
            'Booking state.',
            SchemaMetadata::where('table_name', 'reservations')->where('column_name', 'status')->first()->description
        );
    }

    public function test_import_requires_entries_array(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/schema/import-descriptions', [
            'entries' => 'not-an-array',
        ]);

        $response->assertUnprocessable();
    }
}
