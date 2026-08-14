<?php

namespace App\Services;

use App\Models\SchemaMetadata;
use App\Repositories\SchemaRepository;

class SchemaDescriptionImporter
{
    public function __construct(private SchemaRepository $schemaRepository) {}

    /**
     * Import AI-generated descriptions into the schema metadata table.
     *
     * @param array $entries Flat array of {table, column?, description} objects
     *                       (bare array, or a {"descriptions"|"entries"|"data": [...]} wrapper).
     * @param bool $force Overwrite manually-set descriptions.
     * @return array{updated: int, skipped: int, not_found: int}
     */
    public function import(array $entries, bool $force = false): array
    {
        $results = ['updated' => 0, 'skipped' => 0, 'not_found' => 0];

        foreach ($this->normalize($entries) as $entry) {
            $tableName = $entry['table'];
            $columnName = $entry['column'] ?? null;
            $description = $entry['description'];

            $query = SchemaMetadata::active()
                ->where('table_name', $tableName)
                ->where('metadata_type', $columnName ? 'column' : 'table');

            if ($columnName) {
                $query->where('column_name', $columnName);
            } else {
                $query->whereNull('column_name');
            }

            $row = $query->first();

            if (!$row) {
                $results['not_found']++;
                continue;
            }

            if (!$force && $row->description_source === 'manual') {
                $results['skipped']++;
                continue;
            }

            $row->description = $description;
            $row->description_source = 'manual';
            $row->row_version = ($row->row_version ?? 0) + 1;
            $row->save();
            $results['updated']++;
        }

        if ($results['updated'] > 0) {
            $this->schemaRepository->forgetRegistryCache();
        }

        return $results;
    }

    /**
     * Tolerate common AI output shapes: bare array, wrapper objects, and
     * dot-notation "table.column". Malformed entries are dropped.
     */
    private function normalize(array $input): array
    {
        $entries = $input;

        foreach (['descriptions', 'entries', 'data'] as $key) {
            if (isset($entries[$key]) && is_array($entries[$key])) {
                $entries = $entries[$key];
                break;
            }
        }

        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $table = $entry['table'] ?? null;
            $column = $entry['column'] ?? null;
            $description = $entry['description'] ?? null;

            // Combined-key fallback ("table.column" or "table_column").
            foreach (['table.column', 'table_column'] as $key) {
                if (($table === null || $column === null) && isset($entry[$key]) && is_string($entry[$key]) && str_contains($entry[$key], '.')) {
                    [$table, $column] = explode('.', $entry[$key], 2);
                    break;
                }
            }

            // Dot-notation inside "table" when "column" is absent.
            if (($column === null || (is_string($column) && trim($column) === '')) && is_string($table) && str_contains($table, '.')) {
                [$table, $column] = explode('.', $table, 2);
            }

            if (!is_string($table) || trim($table) === '' || !is_string($description) || trim($description) === '') {
                continue;
            }

            $normalized[] = [
                'table' => trim($table),
                'column' => is_string($column) && trim($column) !== '' ? trim($column) : null,
                'description' => trim($description),
            ];
        }

        return $normalized;
    }
}
