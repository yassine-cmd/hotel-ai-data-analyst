<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create the new expanded table alongside the old one
        Schema::create('schema_metadata_new', function (Blueprint $table) {
            $table->id();
            $table->string('metadata_type'); // 'table' or 'column'

            $table->string('table_name', 191);
            $table->string('column_name', 191)->nullable();

            // Structural — table-level
            $table->unsignedBigInteger('row_count')->nullable();

            // Real FKs from INFORMATION_SCHEMA
            $table->json('foreign_keys')->nullable();

            // Structural — column-level
            $table->unsignedInteger('ordinal_position')->nullable();
            $table->string('data_type', 64)->nullable();
            $table->string('column_type', 255)->nullable();
            $table->string('column_key', 10)->nullable(); // '', 'PRI', 'MUL', 'UNI'
            $table->boolean('is_nullable')->nullable();
            $table->json('enum_values')->nullable();

            // Curated metadata
            $table->text('description')->nullable();
            $table->string('description_source', 10)->default('none');

            $table->json('value_mappings')->nullable();
            $table->string('value_mappings_source', 10)->default('none');

            $table->boolean('is_sensitive')->default(false);
            $table->string('sensitivity_source', 10)->default('default');

            // Table-level only
            $table->json('virtual_foreign_keys')->nullable();
            $table->string('virtual_foreign_keys_source', 10)->default('none');

            // State
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);

            $table->timestamps();

            // Indexes (compatible with both MySQL and SQLite)
            $table->unique(['table_name', 'column_name'], 'uq_metadata_table_column');
            $table->index(['table_name', 'ordinal_position'], 'idx_metadata_lookup');
            $table->index(['metadata_type', 'is_archived'], 'idx_metadata_admin_filters');
        });

        // MySQL-specific: add generated column for null-safe unique key
        // SQLite: the unique key on (table_name, column_name) is sufficient for tests
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE schema_metadata_new
                ADD COLUMN column_key_name VARCHAR(191)
                GENERATED ALWAYS AS (IFNULL(column_name, '')) VIRTUAL
            ");

            DB::statement("
                ALTER TABLE schema_metadata_new
                DROP INDEX uq_metadata_table_column
            ");

            DB::statement("
                ALTER TABLE schema_metadata_new
                ADD UNIQUE KEY uq_metadata_table_column_key (table_name, column_key_name)
            ");
        }

        // Migrate data from legacy table if it exists (production migration path)
        if (Schema::hasTable('schema_metadata')) {
            $now = DB::connection()->getDriverName() === 'mysql' ? 'NOW()' : "'2026-07-24 00:00:00'";

            // Migrate existing table-level rows
            DB::statement("
                INSERT INTO schema_metadata_new
                    (metadata_type, table_name, column_name,
                     description, description_source,
                     value_mappings, value_mappings_source,
                     is_sensitive, sensitivity_source,
                     virtual_foreign_keys, virtual_foreign_keys_source,
                     is_archived, row_version, created_at, updated_at)
                SELECT
                    'table', table_name, NULL,
                    description,
                    CASE WHEN description IS NOT NULL THEN 'manual' ELSE 'none' END,
                    value_mappings,
                    CASE WHEN value_mappings IS NOT NULL AND value_mappings != 'null' THEN 'manual' ELSE 'none' END,
                    COALESCE(is_sensitive, 0),
                    'manual',
                    virtual_foreign_keys,
                    CASE WHEN virtual_foreign_keys IS NOT NULL AND virtual_foreign_keys != 'null' THEN 'manual' ELSE 'none' END,
                    0, 1, {$now}, {$now}
                FROM schema_metadata
                WHERE column_name IS NULL
            ");

            // Migrate existing column-level rows
            DB::statement("
                INSERT INTO schema_metadata_new
                    (metadata_type, table_name, column_name,
                     description, description_source,
                     value_mappings, value_mappings_source,
                     is_sensitive, sensitivity_source,
                     is_archived, row_version, created_at, updated_at)
                SELECT
                    'column', table_name, column_name,
                    description,
                    CASE WHEN description IS NOT NULL THEN 'manual' ELSE 'none' END,
                    value_mappings,
                    CASE WHEN value_mappings IS NOT NULL AND value_mappings != 'null' THEN 'manual' ELSE 'none' END,
                    COALESCE(is_sensitive, 0),
                    'manual',
                    0, 1, {$now}, {$now}
                FROM schema_metadata
                WHERE column_name IS NOT NULL
            ");

            // Rename tables
            Schema::rename('schema_metadata', 'schema_metadata_legacy');
        }

        Schema::rename('schema_metadata_new', 'schema_metadata');
    }

    public function down(): void
    {
        Schema::rename('schema_metadata', 'schema_metadata_new');

        if (Schema::hasTable('schema_metadata_legacy')) {
            Schema::rename('schema_metadata_legacy', 'schema_metadata');
        }

        Schema::dropIfExists('schema_metadata_new');
    }
};
