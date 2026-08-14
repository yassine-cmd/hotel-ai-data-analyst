<?php

namespace App\Console\Commands;

use App\Models\SchemaMetadata;
use Illuminate\Console\Command;

class SchemaExportForAi extends Command
{
    protected $signature = 'schema:export-for-ai
        {--output= : Path to write the JSON file (defaults to stdout)}
        {--only-empty : Only include tables/columns with no manual description yet}';

    protected $description = 'Export schema structure as JSON for external AI enrichment';

    public function handle(): int
    {
        $rows = SchemaMetadata::active()->orderBy('table_name')->orderBy('ordinal_position')->get();

        $tables = [];
        foreach ($rows as $row) {
            $tableName = $row->table_name;
            if (!isset($tables[$tableName])) {
                $tables[$tableName] = [
                    'description' => $row->metadata_type === 'table' ? $row->description : null,
                    'description_source' => $row->metadata_type === 'table' ? $row->description_source : 'none',
                    'foreign_keys' => $row->metadata_type === 'table' ? ($row->foreign_keys ?? []) : [],
                    'virtual_foreign_keys' => $row->metadata_type === 'table' ? ($row->virtual_foreign_keys ?? []) : [],
                    'columns' => [],
                ];
            }
            if ($row->metadata_type === 'column') {
                $tables[$tableName]['columns'][] = [
                    'name' => $row->column_name,
                    'type' => $row->column_type ?? $row->data_type,
                    'key' => $row->column_key ?? '',
                    'nullable' => $row->is_nullable ?? true,
                    'enum_values' => $row->enum_values ?? [],
                    'description' => $row->description,
                    'description_source' => $row->description_source,
                ];
            }
        }

        if ($this->option('only-empty')) {
            foreach ($tables as $name => &$info) {
                unset($info['description'], $info['description_source']);
                $info['columns'] = array_values(array_filter($info['columns'], fn($c) => ($c['description_source'] ?? 'none') !== 'manual'));
                foreach ($info['columns'] as &$c) {
                    unset($c['description'], $c['description_source']);
                }
            }
            $tables = array_filter($tables, fn($info) => !empty($info['columns']));
        } else {
            foreach ($tables as $name => &$info) {
                unset($info['description_source']);
                foreach ($info['columns'] as &$c) {
                    unset($c['description_source']);
                }
            }
        }

        $output = [
            'context' => 'Hotel analytics database. Write concise business descriptions in English for each table and column.',
            'tables' => $tables,
        ];

        $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $path = $this->option('output');
        if ($path) {
            file_put_contents($path, $json);
            $this->info("Exported to {$path}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
