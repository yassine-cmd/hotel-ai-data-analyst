<?php

namespace App\Services;

use App\Models\SchemaMetadata;
use App\Repositories\SchemaRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchemaDiscoveryService
{
    private const DISCOVERY_LOCK_KEY = 'schema_discover_lock';
    private const DISCOVERY_LOCK_TTL = 120;

    public function __construct(
        private SchemaRepository $schemaRepository
    ) {}

    /**
     * Discover the schema from a client's analytics database.
     *
     * Populates the global schema_metadata registry with structural facts,
     * preserves curated metadata for surviving objects, and removes objects
     * (tables/columns) that no longer exist in the live database.
     */
    public function discover(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        bool $force = false,
        bool $removeMissing = true,
        bool $regenerateAutoDescriptions = false
    ): array {
        $lock = Cache::lock(self::DISCOVERY_LOCK_KEY, self::DISCOVERY_LOCK_TTL);

        if (!$lock->get()) {
            throw new \RuntimeException('A discovery run is already in progress.');
        }

        try {
            $liveSchema = $this->fetchLiveSchema($host, $port, $database, $username, $password);

            if ($liveSchema === null) {
                $this->updateDiscoveryState('failed', 'Could not connect to the analytics database.');
                throw new \RuntimeException('Could not connect to the analytics database.');
            }

            $existing = $this->loadExistingRegistry();

            $this->runSafetyChecks($existing, $liveSchema, $force);

            $stats = [
                'tables_seen' => count($liveSchema),
                'tables_added' => 0,
                'tables_updated' => 0,
                'tables_removed' => 0,
                'columns_seen' => 0,
                'columns_added' => 0,
                'columns_updated' => 0,
                'columns_removed' => 0,
            ];

            DB::transaction(function () use (
                $liveSchema,
                $existing,
                $removeMissing,
                $regenerateAutoDescriptions,
                &$stats
            ) {
                $liveTableNames = [];

                foreach ($liveSchema as $tableName => $tableData) {
                    $liveTableNames[] = $tableName;

                    // ── Upsert table row ─────────────────────────────────
                    $existingTable = $existing['tables'][strtolower($tableName)] ?? null;

                    if ($existingTable === null) {
                        // New table — insert with auto description
                        SchemaMetadata::create([
                            'metadata_type' => 'table',
                            'table_name' => $tableName,
                            'row_count' => null,
                            'foreign_keys' => $tableData['foreign_keys'],
                            'description' => $this->generateTableDescription($tableName, $tableData),
                            'description_source' => 'auto',
                            'is_sensitive' => false,
                            'sensitivity_source' => 'default',
                            'is_archived' => false,
                            'row_version' => 1,
                        ]);

                        $stats['tables_added']++;
                    } else {
                        // Existing table — update structural fields only
                        $changed = false;

                        if ($existingTable->is_archived) {
                            $existingTable->is_archived = false;
                            $existingTable->archived_at = null;
                            $changed = true;
                        }

                        if ($existingTable->table_name !== $tableName) {
                            // Adopt the live casing so stored names match the DB
                            // (lower_case_table_names=0 => table names are case-sensitive).
                            $existingTable->table_name = $tableName;
                            $changed = true;
                        }

                        if ($this->jsonChanged($existingTable->foreign_keys, $tableData['foreign_keys'])) {
                            $existingTable->foreign_keys = $tableData['foreign_keys'];
                            $changed = true;
                        }

                        if ($changed) {
                            $existingTable->row_version++;
                            $existingTable->save();
                            $stats['tables_updated']++;
                        }
                    }

                    // ── Upsert columns ────────────────────────────────────
                    $liveColumnNames = [];
                    $liveLowerColumnNames = [];

                    foreach ($tableData['columns'] as $columnData) {
                        $stats['columns_seen']++;
                        $colName = $columnData['column_name'];
                        $liveColumnNames[] = $colName;
                        $liveLowerColumnNames[] = strtolower($colName);

                        $existingColumn = $existing['columns'][strtolower($tableName)][strtolower($colName)] ?? null;

                        if ($existingColumn === null) {
                            // New column — insert with auto description
                            SchemaMetadata::create([
                                'metadata_type' => 'column',
                                'table_name' => $tableName,
                                'column_name' => $colName,
                                'ordinal_position' => $columnData['ordinal_position'],
                                'data_type' => $columnData['data_type'],
                                'column_type' => $columnData['column_type'],
                                'column_key' => $columnData['column_key'],
                                'is_nullable' => $columnData['is_nullable'],
                                'enum_values' => $columnData['enum_values'],
                                'description' => $this->generateColumnDescription($tableName, $columnData),
                                'description_source' => 'auto',
                                'is_sensitive' => false,
                                'sensitivity_source' => 'default',
                                'is_archived' => false,
                                'row_version' => 1,
                            ]);

                            $stats['columns_added']++;
                        } else {
                            // Existing column — update structural fields only
                            $changed = false;

                            if ($existingColumn->is_archived) {
                                $existingColumn->is_archived = false;
                                $existingColumn->archived_at = null;
                                $changed = true;
                            }

                            if ($existingColumn->table_name !== $tableName) {
                                // Adopt the live table casing on the column row too,
                                // so parent/child rows stay consistent.
                                $existingColumn->table_name = $tableName;
                                $changed = true;
                            }

                            if ($existingColumn->column_name !== $colName) {
                                // Adopt the live casing so stored names match the DB.
                                $existingColumn->column_name = $colName;
                                $changed = true;
                            }

                            if ($existingColumn->ordinal_position !== $columnData['ordinal_position']) {
                                $existingColumn->ordinal_position = $columnData['ordinal_position'];
                                $changed = true;
                            }

                            if ($existingColumn->data_type !== $columnData['data_type']) {
                                $existingColumn->data_type = $columnData['data_type'];
                                $changed = true;
                            }

                            if ($existingColumn->column_type !== $columnData['column_type']) {
                                $existingColumn->column_type = $columnData['column_type'];
                                $changed = true;
                            }

                            if ($existingColumn->column_key !== $columnData['column_key']) {
                                $existingColumn->column_key = $columnData['column_key'];
                                $changed = true;
                            }

                            if ($existingColumn->is_nullable !== $columnData['is_nullable']) {
                                $existingColumn->is_nullable = $columnData['is_nullable'];
                                $changed = true;
                            }

                            if ($this->jsonChanged($existingColumn->enum_values, $columnData['enum_values'])) {
                                $existingColumn->enum_values = $columnData['enum_values'];
                                $changed = true;
                            }

                            if ($changed) {
                                $existingColumn->row_version++;
                                $existingColumn->save();
                                $stats['columns_updated']++;
                            }
                        }
                    }

                    // Remove columns that disappeared from the live DB
                    if ($removeMissing) {
                        foreach ($existing['columns'][strtolower($tableName)] ?? [] as $colKey => $existingColumn) {
                            if (!in_array($colKey, $liveLowerColumnNames, true)) {
                                $existingColumn->delete();
                                $stats['columns_removed']++;
                            }
                        }
                    }
                }

                // Remove tables that disappeared from the live DB
                if ($removeMissing) {
                    $liveLowerTableNames = array_map('strtolower', $liveTableNames);

                    foreach ($existing['tables'] as $tableKey => $existingTable) {
                        if (!in_array($tableKey, $liveLowerTableNames, true)) {
                            // Remove child columns too (archived or not)
                            $removedCols = SchemaMetadata::byType('column')
                                ->byTable($existingTable->table_name)
                                ->delete();

                            $existingTable->delete();

                            $stats['columns_removed'] += $removedCols;
                            $stats['tables_removed']++;
                        }
                    }
                }
            });

            $this->schemaRepository->forgetRegistryCache();
            $this->updateDiscoveryState('completed');

            return $stats;
        } catch (\Throwable $e) {
            $this->updateDiscoveryState('failed', $e->getMessage());
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Fetch live schema from a client analytics database via INFORMATION_SCHEMA.
     */
    private function fetchLiveSchema(string $host, int $port, string $database, string $username, string $password): ?array
    {
        $connName = 'schema_discovery';

        try {
            config([
                "database.connections.$connName" => [
                    'driver' => 'mysql',
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                    'charset' => 'utf8mb4',
                    'prefix' => '',
                    'strict' => true,
                ],
            ]);

            DB::connection($connName)->select('SELECT 1');

            $columns = DB::connection($connName)->select("
                SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY, IS_NULLABLE, ORDINAL_POSITION
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ?
                ORDER BY TABLE_NAME, ORDINAL_POSITION
            ", [$database]);

            $foreignKeys = DB::connection($connName)->select("
                SELECT
                    kcu.TABLE_NAME,
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    kcu.CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                    ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                    AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                    AND tc.TABLE_NAME = kcu.TABLE_NAME
                WHERE kcu.TABLE_SCHEMA = ?
                    AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
            ", [$database]);

            $schema = [];

            foreach ($columns as $row) {
                $tName = $row->TABLE_NAME;

                if (!isset($schema[$tName])) {
                    $schema[$tName] = [
                        'foreign_keys' => [],
                        'columns' => [],
                    ];
                }

                $enumValues = null;
                if ($row->DATA_TYPE === 'enum') {
                    preg_match_all("/'([^']+)'/", $row->COLUMN_TYPE, $matches);
                    $enumValues = $matches[1] ?? null;
                }

                $schema[$tName]['columns'][] = [
                    'column_name' => $row->COLUMN_NAME,
                    'ordinal_position' => (int) $row->ORDINAL_POSITION,
                    'data_type' => $row->DATA_TYPE,
                    'column_type' => $row->COLUMN_TYPE,
                    'column_key' => $row->COLUMN_KEY ?? '',
                    'is_nullable' => $row->IS_NULLABLE === 'YES',
                    'enum_values' => $enumValues,
                ];
            }

            foreach ($foreignKeys as $row) {
                $tName = $row->TABLE_NAME;
                if (isset($schema[$tName])) {
                    $schema[$tName]['foreign_keys'][] = [
                        'constraint' => $row->CONSTRAINT_NAME,
                        'column' => $row->COLUMN_NAME,
                        'ref_table' => $row->REFERENCED_TABLE_NAME,
                        'ref_col' => $row->REFERENCED_COLUMN_NAME,
                    ];
                }
            }

            return $schema;
        } catch (\Exception $e) {
            Log::error('Schema discovery connection failed', [
                'host' => $host,
                'database' => $database,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            DB::purge($connName);
        }
    }

    /**
     * Load existing schema_metadata rows into indexed arrays.
     *
     * Keys are normalized to lowercase so lookups are case-insensitive. The DB
     * unique key (table_name, column_key_name) uses a case-insensitive collation
     * (utf8mb4_unicode_ci), so live rows whose case differs from stored ones must
     * match (and then be updated to the live casing) instead of colliding on insert.
     */
    private function loadExistingRegistry(): array
    {
        $allRows = SchemaMetadata::all();

        $tables = [];
        $columns = [];

        foreach ($allRows as $row) {
            if ($row->metadata_type === 'table') {
                $tables[strtolower($row->table_name)] = $row;
            } else {
                $columns[strtolower($row->table_name)][strtolower((string) $row->column_name)] = $row;
            }
        }

        return [
            'tables' => $tables,
            'columns' => $columns,
        ];
    }

    /**
     * Safety checks to prevent catastrophic mistakes during discovery.
     */
    private function runSafetyChecks(array $existing, array $liveSchema, bool $force): void
    {
        $existingTableCount = count($existing['tables']);
        $liveTableCount = count($liveSchema);

        if ($existingTableCount > 0 && $liveTableCount === 0 && !$force) {
            throw new \RuntimeException(
                'Live database returned zero tables. Refusing to archive existing schema. Use force=true to override.'
            );
        }

        if ($existingTableCount >= 10 && $liveTableCount < ($existingTableCount * 0.5) && !$force) {
            throw new \RuntimeException(
                'Live database has far fewer tables than expected (' . $liveTableCount
                . ' vs ' . $existingTableCount . ' existing). Refusing to continue without force=true.'
            );
        }
    }

    /**
     * Update the schema_discovery_state table.
     */
    private function updateDiscoveryState(string $status, ?string $error = null): void
    {
        try {
            DB::table('schema_discovery_state')
                ->where('id', 1)
                ->update([
                    'last_discovered_at' => $status === 'completed' ? now() : null,
                    'last_status' => $status,
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            Log::warning('Could not update discovery state', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Compare two JSON-compatible values for changes.
     */
    private function jsonChanged(mixed $old, mixed $new): bool
    {
        return json_encode($old) !== json_encode($new);
    }

    // ── Heuristic description generation ─────────────────────────────────

    private function generateTableDescription(string $tableName, array $tableData): string
    {
        $humanName = $this->humanizeName($tableName);
        $colCount = count($tableData['columns']);
        $fkCount = count($tableData['foreign_keys']);

        $parts = ["{$humanName} table."];

        if ($colCount > 0) {
            $parts[] = "{$colCount} columns.";
        }

        if ($fkCount > 0) {
            $fkRefs = array_unique(array_column($tableData['foreign_keys'], 'ref_table'));
            $parts[] = 'References: ' . implode(', ', $fkRefs) . '.';
        }

        return implode(' ', $parts);
    }

    private function generateColumnDescription(string $tableName, array $columnData): string
    {
        $name = $columnData['column_name'];
        $type = $columnData['data_type'];

        // is_* / has_* prefix
        if (preg_match('/^(is|has)_(.+)$/i', $name, $m)) {
            return 'Boolean flag indicating whether ' . $this->humanizeName($m[2]) . '.';
        }

        // *_at suffix (timestamp)
        if (str_ends_with($name, '_at')) {
            return 'Timestamp indicating when ' . $this->humanizeName(substr($name, 0, -3)) . '.';
        }

        // *_id suffix (foreign key reference)
        if (str_ends_with($name, '_id')) {
            $ref = $this->humanizeName(substr($name, 0, -3));
            return "Reference to related {$ref} record.";
        }

        // *_date suffix
        if (str_ends_with($name, '_date')) {
            return 'Date when ' . $this->humanizeName(substr($name, 0, -5)) . '.';
        }

        // Enum type
        if ($type === 'enum' && !empty($columnData['enum_values'])) {
            return $this->humanizeName($name) . '. Allowed values: ' . implode(', ', $columnData['enum_values']) . '.';
        }

        return $this->humanizeName($name) . '.';
    }

    private function humanizeName(string $name): string
    {
        // snake_case to Title Case with spaces
        $result = str_replace('_', ' ', $name);
        return ucwords($result);
    }
}
