<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\SchemaMetadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchemaRepository
{
    private const REGISTRY_CACHE_KEY = 'schema_registry';
    private const REGISTRY_CACHE_TTL = 300;
    private const ROW_COUNT_CACHE_PREFIX = 'schema_row_counts:';
    private const ROW_COUNT_CACHE_TTL = 60;

    /**
     * Get all non-archived table-level rows.
     */
    public function getTables(): Collection
    {
        return SchemaMetadata::active()
            ->byType('table')
            ->orderBy('table_name')
            ->get();
    }

    /**
     * Get all non-archived column-level rows.
     */
    public function getColumns(): Collection
    {
        return SchemaMetadata::active()
            ->byType('column')
            ->orderBy('table_name')
            ->orderBy('ordinal_position')
            ->get();
    }

    /**
     * Get all rows for a specific table.
     */
    public function getByTable(string $tableName): Collection
    {
        return SchemaMetadata::active()
            ->byTable($tableName)
            ->orderBy('ordinal_position')
            ->get();
    }

    /**
     * Find a single row by ID.
     */
    public function findById(int $id): ?SchemaMetadata
    {
        return SchemaMetadata::find($id);
    }

    /**
     * Check if a table exists in the registry.
     */
    public function tableExists(string $tableName): bool
    {
        return SchemaMetadata::byType('table')
            ->active()
            ->byTable($tableName)
            ->exists();
    }

    /**
     * Build the client-specific enriched schema payload.
     *
     * Layer 1: Global registry (cached 5 min)
     * Layer 2: Per-client row counts (cached 60 sec)
     * Result: merged schema filtered to tables present in client DB
     */
    public function buildClientSchema(Client $client): array
    {
        $registry = $this->getGlobalRegistry();
        $rowCounts = $this->fetchRowCounts($client);

        $tables = [];
        $sensitiveTables = [];
        $sensitiveColumns = ['*' => []];

        foreach ($registry['tables'] as $tableName => $table) {
            // Skip tables that don't exist in this client's DB (if we have row counts)
            if ($rowCounts !== null && !isset($rowCounts[$tableName])) {
                continue;
            }

            $isSensitive = $table['is_sensitive'] ?? false;

            $entry = [
                'row_count' => $rowCounts[$tableName] ?? null,
                'description' => $table['description'] ?? null,
                'is_sensitive' => $isSensitive,
                'foreign_keys' => $table['foreign_keys'] ?? [],
                'virtual_foreign_keys' => $table['virtual_foreign_keys'] ?? [],
                'columns' => [],
            ];

            if ($isSensitive) {
                $sensitiveTables[] = $tableName;
            }

            foreach ($registry['columns'][$tableName] ?? [] as $col) {
                $colSensitive = $col['is_sensitive'] ?? false;

                $entry['columns'][] = [
                    'name' => $col['column_name'],
                    'type' => $col['data_type'],
                    'enum' => $this->formatEnum($col['enum_values']),
                    'key' => $col['column_key'] ?? '',
                    'description' => $col['description'] ?? null,
                    'values' => $col['value_mappings'] ?? null,
                    'is_sensitive' => $colSensitive,
                ];

                if ($colSensitive) {
                    $sensitiveColumns[$tableName][] = $col['column_name'];
                }
            }

            // Mark virtual FKs
            foreach ($entry['foreign_keys'] as &$fk) {
                $isVirtual = $this->isVirtualFk($tableName, $fk['column'] ?? '');
                if ($isVirtual) {
                    $fk['_virtual'] = true;
                }
            }
            unset($fk);

            $tables[$tableName] = $entry;
        }

        return [
            'tables' => $tables,
            'sensitive_tables' => $sensitiveTables,
            'sensitive_columns' => $sensitiveColumns,
        ];
    }

    /**
     * Sensitive (protected) table names for a client — the global privacy
     * rules that apply to every user, admins included. Mirrors the
     * `sensitive_tables` slice of buildClientSchema without building the
     * full schema, so it is cheap enough for the data-plane hot path.
     */
    public function sensitiveTables(Client $client): array
    {
        $registry = $this->getGlobalRegistry();
        $rowCounts = $this->fetchRowCounts($client);

        $sensitive = [];
        foreach ($registry['tables'] as $tableName => $table) {
            if (($table['is_sensitive'] ?? false)
                && ($rowCounts === null || isset($rowCounts[$tableName]))) {
                $sensitive[] = $tableName;
            }
        }

        return $sensitive;
    }

    /**
     * Forget the global registry cache.
     */
    public function forgetRegistryCache(): void
    {
        Cache::forget(self::REGISTRY_CACHE_KEY);
    }

    // ── Private helpers ─────────────────────────────────────────────────

    /**
     * Load the global schema registry (Layer 1), cached.
     */
    private function getGlobalRegistry(): array
    {
        return Cache::remember(self::REGISTRY_CACHE_KEY, self::REGISTRY_CACHE_TTL, function () {
            $tables = $this->getTables();
            $columns = $this->getColumns();

            $tableMap = [];
            $columnMap = [];

            foreach ($tables as $t) {
                $tableMap[$t->table_name] = [
                    'description' => $t->description,
                    'is_sensitive' => $t->is_sensitive,
                    'foreign_keys' => $t->foreign_keys ?? [],
                    'virtual_foreign_keys' => $t->virtual_foreign_keys ?? [],
                ];
            }

            foreach ($columns as $c) {
                $columnMap[$c->table_name][] = [
                    'column_name' => $c->column_name,
                    'data_type' => $c->data_type,
                    'enum_values' => $c->enum_values,
                    'column_key' => $c->column_key,
                    'description' => $c->description,
                    'value_mappings' => $c->value_mappings,
                    'is_sensitive' => $c->is_sensitive,
                ];
            }

            return [
                'tables' => $tableMap,
                'columns' => $columnMap,
            ];
        });
    }

    /**
     * Fetch per-client row counts, cached briefly.
     * Returns null if the client DB is unreachable.
     */
    private function fetchRowCounts(Client $client): ?array
    {
        $key = self::ROW_COUNT_CACHE_PREFIX . $client->id;

        // Cache::remember treats a null result as a miss, so an unreachable
        // client DB (queryRowCounts returns null) would be re-attempted on
        // every data-plane request — each paying a full connect timeout.
        // Cache a sentinel instead so the failure is only retried after TTL.
        if (Cache::has($key)) {
            $value = Cache::get($key);

            return $value instanceof \stdClass ? null : $value;
        }

        $rowCounts = $this->queryRowCounts($client);
        Cache::put($key, $rowCounts ?? new \stdClass(), self::ROW_COUNT_CACHE_TTL);

        return $rowCounts;
    }

    /**
     * Query the client's analytics database for row counts.
     */
    private function queryRowCounts(Client $client): ?array
    {
        $connName = 'analytics_rowcount_' . $client->id;

        try {
            config([
                "database.connections.$connName" => [
                    'driver' => 'mysql',
                    'host' => $client->analytics_db_host,
                    'port' => $client->analytics_db_port,
                    'database' => $client->analytics_db_name,
                    'username' => $client->analytics_db_user,
                    'password' => $client->decrypted_password,
                    'charset' => 'utf8mb4',
                    'prefix' => '',
                    'strict' => true,
                ],
            ]);

            $rows = DB::connection($connName)->select("
                SELECT TABLE_NAME, TABLE_ROWS
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ", [$client->analytics_db_name]);

            $rowCounts = [];
            foreach ($rows as $r) {
                $rowCounts[$r->TABLE_NAME] = (int) $r->TABLE_ROWS;
            }

            return $rowCounts;
        } catch (\Exception $e) {
            Log::warning("Could not fetch row counts for client {$client->id}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            DB::purge($connName);
        }
    }

    /**
     * Format enum values array to a comma-separated string (matching current format).
     */
    private function formatEnum(?array $enumValues): ?string
    {
        if (empty($enumValues)) {
            return null;
        }
        return implode(',', $enumValues);
    }

    /**
     * Check if a foreign key is virtual (exists in virtual_foreign_keys but not in real foreign_keys).
     */
    private function isVirtualFk(string $tableName, string $column): bool
    {
        static $cache = [];

        if (!isset($cache[$tableName])) {
            $table = SchemaMetadata::byType('table')
                ->active()
                ->byTable($tableName)
                ->first();

            if (!$table || empty($table->virtual_foreign_keys)) {
                $cache[$tableName] = [];
                return false;
            }

            $cache[$tableName] = array_column($table->virtual_foreign_keys, 'column');
        }

        return in_array($column, $cache[$tableName]);
    }
}
