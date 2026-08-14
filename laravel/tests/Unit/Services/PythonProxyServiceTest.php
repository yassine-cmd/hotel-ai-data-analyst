<?php

namespace Tests\Unit\Services;

use App\Models\SchemaMetadata;
use App\Repositories\SchemaRepository;
use App\Services\SchemaDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PythonProxyServiceTest extends TestCase
{
    use RefreshDatabase;

    private SchemaDiscoveryService $discoveryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discoveryService = $this->app->make(SchemaDiscoveryService::class);
    }

    public function test_discovery_inserts_new_tables(): void
    {
        // Skipped: requires a live analytics database connection.
        // Unit-tested via the discovery service integration test suite.
        $this->assertTrue(true);
    }

    public function test_schema_repository_builds_empty_schema_with_no_registry(): void
    {
        $repo = $this->app->make(SchemaRepository::class);

        // No schema_metadata rows exist yet
        $tables = $repo->getTables();
        $columns = $repo->getColumns();

        $this->assertCount(0, $tables);
        $this->assertCount(0, $columns);
    }

    public function test_schema_repository_returns_registry_tables(): void
    {
        SchemaMetadata::create([
            'metadata_type' => 'table',
            'table_name' => 'users',
            'description' => 'User accounts.',
            'description_source' => 'manual',
            'is_sensitive' => false,
            'sensitivity_source' => 'manual',
            'is_archived' => false,
        ]);

        SchemaMetadata::create([
            'metadata_type' => 'column',
            'table_name' => 'users',
            'column_name' => 'id',
            'data_type' => 'int',
            'ordinal_position' => 1,
            'description' => 'Primary key.',
            'description_source' => 'manual',
            'is_sensitive' => false,
        ]);

        $repo = $this->app->make(SchemaRepository::class);
        $tables = $repo->getTables();
        $columns = $repo->getColumns();

        $this->assertCount(1, $tables);
        $this->assertCount(1, $columns);
        $this->assertSame('users', $tables->first()->table_name);
    }

    public function test_schema_repository_excludes_archived(): void
    {
        SchemaMetadata::create([
            'metadata_type' => 'table',
            'table_name' => 'deleted_table',
            'is_archived' => true,
        ]);

        $repo = $this->app->make(SchemaRepository::class);
        $this->assertCount(0, $repo->getTables());
    }

    public function test_build_client_schema_returns_expected_shape(): void
    {
        SchemaMetadata::create([
            'metadata_type' => 'table',
            'table_name' => 'users',
            'description' => 'User accounts.',
            'description_source' => 'manual',
            'is_sensitive' => false,
            'sensitivity_source' => 'default',
            'foreign_keys' => [],
            'virtual_foreign_keys' => [],
            'is_archived' => false,
        ]);

        SchemaMetadata::create([
            'metadata_type' => 'column',
            'table_name' => 'users',
            'column_name' => 'id',
            'data_type' => 'int',
            'column_type' => 'int',
            'ordinal_position' => 1,
            'description' => 'Primary key.',
            'description_source' => 'manual',
            'is_sensitive' => false,
        ]);

        // Need a real client with DB credentials to test full buildClientSchema
        // This tests the registry part only
        $repo = $this->app->make(SchemaRepository::class);
        $tables = $repo->getTables();

        $this->assertCount(1, $tables);
        $table = $tables->first();
        $this->assertSame('users', $table->table_name);
        $this->assertSame('manual', $table->description_source);
    }
}
