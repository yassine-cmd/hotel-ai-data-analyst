<?php

namespace App\Console\Commands;

use App\Services\SchemaDescriptionImporter;
use Illuminate\Console\Command;

class SchemaImportDescriptions extends Command
{
    protected $signature = 'schema:import-descriptions
        {file : Path to the JSON responses file from the AI}
        {--force : Overwrite existing manually-set descriptions}';

    protected $description = 'Import AI-generated descriptions into the schema metadata table';

    public function handle(SchemaDescriptionImporter $importer): int
    {
        $path = $this->argument('file');
        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $input = json_decode(file_get_contents($path), true);
        if ($input === null || !is_array($input)) {
            $this->error('Invalid JSON — expected an array of {table, column?, description} objects.');
            return self::FAILURE;
        }

        $results = $importer->import($input, $this->option('force'));

        $this->info("Done. Updated: {$results['updated']}, Skipped: {$results['skipped']}, Not found: {$results['not_found']}");

        return self::SUCCESS;
    }
}
