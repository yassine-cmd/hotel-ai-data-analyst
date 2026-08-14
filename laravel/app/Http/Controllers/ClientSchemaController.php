<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Repositories\SchemaRepository;
use App\Services\SuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClientSchemaController extends Controller
{
    public function __construct(
        private SchemaRepository $schemaRepository,
        private SuggestionService $suggestionService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->client_id) {
            $client = $user->client;
        } elseif ($user instanceof \App\Models\Admin) {
            $inputClientId = $request->input('client_id');
            $client = $inputClientId ? Client::find((int) $inputClientId) : null;
        } else {
            $client = null;
        }

        if (!$client) {
            return response()->json([
                'tables' => [],
                'sensitive_tables' => [],
                'sensitive_columns' => ['*' => []],
                'suggestions' => [],
            ]);
        }

        $merged = $this->schemaRepository->buildClientSchema($client);

        $suggestions = [];
        try {
            $suggestions = $this->suggestionService->generate($merged);
        } catch (\Throwable $e) {
            Log::warning('SuggestionService::generate failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'tables' => $merged['tables'],
            'sensitive_tables' => $merged['sensitive_tables'],
            'sensitive_columns' => $merged['sensitive_columns'],
            'suggestions' => $suggestions,
        ]);
    }
}
