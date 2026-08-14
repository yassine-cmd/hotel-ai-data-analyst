<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SchemaMetadata;
use App\Repositories\SchemaRepository;
use App\Services\SchemaDescriptionImporter;
use App\Services\SchemaDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSchemaController extends Controller
{
    public function __construct(
        private SchemaRepository $schemaRepository,
        private SchemaDiscoveryService $discoveryService,
        private SchemaDescriptionImporter $descriptionImporter
    ) {}

    public function listMetadata(Request $request): JsonResponse
    {
        $query = SchemaMetadata::query();

        // Filter by type
        if ($request->filled('type') && $request->type !== 'all') {
            $query->byType($request->type);
        }

        // Filter by table name
        if ($request->filled('table_name')) {
            $query->byTable($request->table_name);
        }

        // Include archived? When true, return all rows (both active and archived).
        // The frontend uses each row's is_archived field to display status.
        if (!$request->boolean('include_archived', false)) {
            $query->active();
        }

        // Search
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qry) use ($q) {
                $qry->where('table_name', 'like', "%{$q}%")
                    ->orWhere('column_name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        // Enrichment filters
        if ($request->filled('filter')) {
            match ($request->filter) {
                'enriched' => $query->where(function ($q) {
                    $q->whereNotNull('description')
                        ->orWhereNotNull('value_mappings')
                        ->orWhereNotNull('virtual_foreign_keys');
                }),
                'empty' => $query->whereNull('description')
                    ->whereNull('value_mappings')
                    ->where(function ($q) {
                        $q->whereNull('virtual_foreign_keys')
                            ->orWhere('virtual_foreign_keys', '{}');
                    }),
                'sensitive' => $query->where('is_sensitive', true),
                'archived' => $query->where('is_archived', true),
                default => null,
            };
        }

        $rows = $query->orderBy('table_name')
            ->orderBy('ordinal_position')
            ->get()
            ->toArray();

        // Ensure JSON fields are arrays
        foreach ($rows as &$r) {
            if (is_string($r['foreign_keys'] ?? null)) {
                $r['foreign_keys'] = json_decode($r['foreign_keys'], true);
            }
            if (is_string($r['value_mappings'] ?? null)) {
                $r['value_mappings'] = json_decode($r['value_mappings'], true);
            }
            if (is_string($r['virtual_foreign_keys'] ?? null)) {
                $r['virtual_foreign_keys'] = json_decode($r['virtual_foreign_keys'], true);
            }
            if (is_string($r['enum_values'] ?? null)) {
                $r['enum_values'] = json_decode($r['enum_values'], true);
            }
        }

        // Add discovery state
        $discoveryState = DB::table('schema_discovery_state')->where('id', 1)->first();

        return response()->json([
            'metadata' => $rows,
            'discovery' => $discoveryState ? [
                'last_discovered_at' => $discoveryState->last_discovered_at,
                'last_status' => $discoveryState->last_status,
                'last_error' => $discoveryState->last_error,
            ] : null,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = SchemaMetadata::find($id);

        if (!$row) {
            return response()->json(['error' => 'Metadata row not found.'], 404);
        }

        $request->validate([
            'description' => 'nullable|string',
            'value_mappings' => 'nullable|array',
            'is_sensitive' => 'nullable|boolean',
            'virtual_foreign_keys' => 'nullable|array',
            'row_version' => 'nullable|integer',
        ]);

        // Optimistic locking
        if ($request->has('row_version') && (int) $request->row_version !== $row->row_version) {
            return response()->json([
                'error' => 'VERSION_CONFLICT',
                'message' => 'This metadata was modified by another user.',
                'current_row_version' => $row->row_version,
            ], 409);
        }

        // Update only curated fields
        if ($request->has('description')) {
            $row->description = $request->description;
            $row->description_source = 'manual';
        }

        if ($request->has('value_mappings')) {
            $row->value_mappings = $request->value_mappings;
            $row->value_mappings_source = 'manual';
        }

        if ($request->has('is_sensitive')) {
            $row->is_sensitive = $request->boolean('is_sensitive');
            $row->sensitivity_source = 'manual';
        }

        if ($request->has('virtual_foreign_keys')) {
            $row->virtual_foreign_keys = $request->virtual_foreign_keys;
            $row->virtual_foreign_keys_source = 'manual';
        }

        $row->row_version++;
        $row->save();

        $this->schemaRepository->forgetRegistryCache();

        return response()->json([
            'data' => [
                'id' => $row->id,
                'metadata_type' => $row->metadata_type,
                'table_name' => $row->table_name,
                'column_name' => $row->column_name,
                'description' => $row->description,
                'description_source' => $row->description_source,
                'value_mappings' => $row->value_mappings,
                'value_mappings_source' => $row->value_mappings_source,
                'is_sensitive' => $row->is_sensitive,
                'sensitivity_source' => $row->sensitivity_source,
                'virtual_foreign_keys' => $row->virtual_foreign_keys,
                'virtual_foreign_keys_source' => $row->virtual_foreign_keys_source,
                'row_version' => $row->row_version,
            ],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $row = SchemaMetadata::find($id);

        if (!$row) {
            return response()->json(['error' => 'Metadata row not found.'], 404);
        }

        $hard = request()->boolean('hard', false);

        if ($hard) {
            $row->delete();
        } else {
            $row->is_archived = true;
            $row->archived_at = now();
            $row->row_version++;
            $row->save();
        }

        $this->schemaRepository->forgetRegistryCache();

        return response()->json(['status' => 'ok']);
    }

    public function importDescriptions(Request $request): JsonResponse
    {
        $request->validate([
            'entries' => 'required|array',
            'force' => 'nullable|boolean',
        ]);

        $results = $this->descriptionImporter->import(
            $request->input('entries'),
            $request->boolean('force', false)
        );

        return response()->json($results);
    }

    public function discover(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'force' => 'nullable|boolean',
            'remove_missing' => 'nullable|boolean',
            'regenerate_auto_descriptions' => 'nullable|boolean',
        ]);

        $client = Client::findOrFail($request->client_id);

        try {
            $stats = $this->discoveryService->discover(
                host: $client->analytics_db_host,
                port: $client->analytics_db_port,
                database: $client->analytics_db_name,
                username: $client->analytics_db_user,
                password: $client->decrypted_password,
                force: $request->boolean('force', false),
                removeMissing: $request->boolean('remove_missing', true),
                regenerateAutoDescriptions: $request->boolean('regenerate_auto_descriptions', false),
            );

            return response()->json([
                'data' => [
                    'client_id' => $client->id,
                    'status' => 'completed',
                    'stats' => $stats,
                ],
            ]);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CREDENTIAL_DECRYPT_FAILED',
                    'message' => 'Client analytics DB credentials cannot be decrypted (CLIENT_CREDENTIALS_KEY mismatch). Re-save the client\'s DB passwords with `php artisan clients:set-credentials`.',
                ],
            ], 502);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'DISCOVERY_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }
}
