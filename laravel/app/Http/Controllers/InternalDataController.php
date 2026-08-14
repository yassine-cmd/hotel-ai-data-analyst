<?php

namespace App\Http\Controllers;

use App\Services\DataQueryException;
use App\Services\DataQueryService;
use App\Services\ClientSignatureService;
use App\Services\TokenUsageService;
use App\Services\UserAccessService;
use App\Services\AuditLogger;
use App\Repositories\SchemaRepository;
use App\Models\Client;
use App\Models\SessionMetadata;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InternalDataController extends Controller
{
    public function __construct(
        private DataQueryService $dataQuery,
        private UserAccessService $userAccess,
        private SchemaRepository $schemaRepository,
        private ClientSignatureService $signature,
        private TokenUsageService $tokenUsage,
        private AuditLogger $audit,
    ) {}

    public function query(Request $request): JsonResponse
    {
        // Force JSON responses for this machine-to-machine endpoint so that
        // validation errors return 422 JSON instead of 302 redirects.
        $request->headers->set('Accept', 'application/json');

        $token = $request->header('X-Delegation-Token');
        if (!$token) {
            $this->audit->warning('security.delegation_missing', ['ip' => $request->ip()]);

            return response()->json([
                'error' => ['code' => 'ACCESS_DENIED', 'message' => 'Missing delegation token', 'retryable' => false],
            ], 403);
        }

        Log::debug('DATA_PLANE_REQUEST_RAW', ['body' => $request->all(), 'content' => $request->getContent()]);
        $validated = $request->validate([
            'datasource_id' => 'required|string',
            'sql' => 'required|string',
            'user_ref' => 'required|integer',
            'referenced_tables' => 'nullable|array',
            'referenced_tables.*' => 'string',
            'max_rows' => 'nullable|integer|min:0|max:100000',
            'timeout_ms' => 'nullable|integer|min:1000|max:60000',
            'query_id' => 'nullable|string',
        ]);

        $referencedTables = $validated['referenced_tables'] ?? [];

        $datasourceId = $validated['datasource_id'];
        $clientId = (int) str_replace('.analytics', '', $datasourceId);
        $client = \App\Models\Client::find($clientId);

        if (!$client) {
            return response()->json([
                'error' => ['code' => 'DATASOURCE_NOT_FOUND', 'message' => "Datasource not found: $datasourceId", 'retryable' => false, 'query_id' => $validated['query_id'] ?? Str::uuid()->toString()],
            ], 404);
        }

        $claims = $this->signature->verifyDelegation($token, (string) $clientId, $client->public_key);
        if (!$claims) {
            $this->audit->warning('security.delegation_invalid', [
                'client_id' => $clientId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => ['code' => 'ACCESS_DENIED', 'message' => 'Invalid delegation token', 'retryable' => false],
            ], 403);
        }

        $sessionId = $claims['session_id'] ?? null;
        if (!$sessionId || !SessionMetadata::where('session_id', $sessionId)->where('client_id', $clientId)->exists()) {
            return response()->json([
                'error' => ['code' => 'SESSION_NOT_FOUND', 'message' => 'Session is not active', 'retryable' => false],
            ], 403);
        }

        $sql = $validated['sql'];
        $maxRows = min((int) ($validated['max_rows'] ?? 10000), 100000);
        $timeoutMs = (int) ($validated['timeout_ms'] ?? 15000);
        $queryId = $validated['query_id'] ?? Str::uuid()->toString();

        $user = User::where('id', (int) $validated['user_ref'])->where('client_id', $clientId)->first();
        if (!$user) {
            return response()->json([
                'error' => ['code' => 'USER_NOT_FOUND', 'message' => 'User is not associated with this datasource', 'retryable' => false, 'query_id' => $queryId],
            ], 403);
        }

        // Global sensitivity wins: protected tables are blocked for EVERY user,
        // admins included. Checked before the permission allow-list so a grant
        // can never override a sensitive table.
        $sensitiveLower = [];
        foreach ($this->schemaRepository->sensitiveTables($client) as $t) {
            $sensitiveLower[strtolower((string) $t)] = true;
        }
        $sensitiveDenied = array_values(array_filter(
            $referencedTables,
            fn ($table) => isset($sensitiveLower[strtolower((string) $table)])
        ));

        if (!empty($sensitiveDenied)) {
            return response()->json([
                'error' => [
                    'code' => 'TABLE_SENSITIVE',
                    'message' => 'Access denied to protected table(s): ' . implode(', ', $sensitiveDenied) . '. These tables hold regulated personal data and are not queryable by any user.',
                    'retryable' => false,
                    'query_id' => $queryId,
                ],
            ], 403);
        }

        $allowed = $this->userAccess->allowedTables($user);
        if ($allowed !== null) {
            $allowedLower = array_flip($allowed);
            $denied = array_values(array_filter(
                $referencedTables,
                fn ($table) => !isset($allowedLower[strtolower((string) $table)])
            ));

            if (!empty($denied)) {
                return response()->json([
                    'error' => [
                        'code' => 'TABLE_NOT_ALLOWED',
                        'message' => 'Access denied to table(s): ' . implode(', ', $denied),
                        'retryable' => false,
                        'query_id' => $queryId,
                    ],
                ], 403);
            }
        }

        $sqlTrimmed = trim($sql);
        if (!preg_match('/^\s*(?:SELECT|WITH|\(|WITH\s+RECURSIVE)\s/i', $sqlTrimmed)) {
            return response()->json([
                'error' => ['code' => 'INVALID_SQL', 'message' => 'Only SELECT queries are allowed', 'retryable' => false, 'query_id' => $queryId],
            ], 400);
        }
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sqlTrimmed)) {
            return response()->json([
                'error' => ['code' => 'INVALID_SQL', 'message' => 'INTO OUTFILE/DUMPFILE is not allowed', 'retryable' => false, 'query_id' => $queryId],
            ], 400);
        }
        if (preg_match('/;\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|GRANT|REVOKE|LOCK|UNLOCK|CALL|PREPARE|EXECUTE)\s/i', $sqlTrimmed)) {
            return response()->json([
                'error' => ['code' => 'INVALID_SQL', 'message' => 'Multi-statement queries with DDL/DML are not allowed', 'retryable' => false, 'query_id' => $queryId],
            ], 400);
        }

        try {
            $result = $this->dataQuery->execute($datasourceId, $sql, $maxRows, $timeoutMs, $queryId);

            $this->audit->info('api.data_query', [
                'client_id' => $clientId,
                'session_id' => $sessionId,
                'query_id' => $queryId,
            ]);

            return response()->json($result);
        } catch (DataQueryException $e) {
            $statusCode = match ($e->getErrorCode()) {
                'DATASOURCE_NOT_FOUND' => 404,
                'DB_CONNECTION_FAILED' => 502,
                'QUERY_TIMEOUT' => 504,
                default => 502,
            };

            $this->audit->error('error.data_plane', [
                'client_id' => $clientId,
                'session_id' => $sessionId,
                'query_id' => $e->getQueryId(),
                'code' => $e->getErrorCode(),
                'detail' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                    'retryable' => $e->isRetryable(),
                    'query_id' => $e->getQueryId(),
                ],
            ], $statusCode);
        }
    }

    /**
     * Turn-completion endpoint (called by Python). Persists the usage deltas
     * of a finished turn, keyed by `turn_uuid` so retries are idempotent.
     * Delegation-token authenticated, like /query.
     */
    public function turnComplete(Request $request): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        $token = $request->header('X-Delegation-Token');
        if (!$token) {
            return response()->json([
                'error' => ['code' => 'ACCESS_DENIED', 'message' => 'Missing delegation token', 'retryable' => false],
            ], 403);
        }

        $validated = $request->validate([
            'client_id' => 'required|integer',
            'session_id' => 'required|string',
            'turn_uuid' => 'required|string',
            'user_ref' => 'nullable|integer',
            'usage' => 'required|array',
        ]);

        $client = Client::find((int) $validated['client_id']);
        if (!$client) {
            return response()->json([
                'error' => ['code' => 'CLIENT_NOT_FOUND', 'message' => 'Client not found', 'retryable' => false],
            ], 404);
        }

        $claims = $this->signature->verifyDelegation($token, (string) $client->id, $client->public_key);
        if (!$claims || ($claims['session_id'] ?? null) !== $validated['session_id']) {
            $this->audit->warning('security.delegation_invalid', [
                'client_id' => $client->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => ['code' => 'ACCESS_DENIED', 'message' => 'Invalid delegation token', 'retryable' => false],
            ], 403);
        }

        $userId = $validated['user_ref'] ?? null;
        $userName = null;
        if ($userId !== null) {
            $user = User::where('id', $userId)->where('client_id', $client->id)->first();
            if ($user) {
                $userName = $user->name;
            }
        }

        $this->tokenUsage->recordUsage(
            $validated['session_id'],
            $client,
            $userId,
            $userName,
            $validated['usage'],
            $validated['turn_uuid']
        );

        $this->audit->info('api.turn_complete', [
            'client_id' => $client->id,
            'session_id' => $validated['session_id'],
            'turn_uuid' => $validated['turn_uuid'],
        ]);

        return response()->json(['status' => 'ok', 'turn_uuid' => $validated['turn_uuid']]);
    }

    /**
     * Return the client_id -> public_key map for Python to verify client
     * instances' signatures. Authenticated by Python's own Ed25519 signature
     * over the (empty) GET body.
     */
    public function publicKeys(Request $request): JsonResponse
    {
        $keys = [];
        foreach (Client::whereNotNull('public_key')->pluck('public_key', 'id') as $id => $pub) {
            $keys[(string) $id] = $pub;
        }

        return response()->json(['clients' => $keys]);
    }

    /**
     * System audit event receiver (called by Python). Loopback-only, like
     * /internal/public-keys. Writes locally only — never re-forwards — so a
     * relayed event can never be echoed back to Python.
     */
    public function reportEvent(Request $request): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        $validated = $request->validate([
            'level' => 'required|string|in:info,warning,error,critical',
            'event' => 'required|string|max:120',
            'client_id' => 'nullable|integer',
            'session_id' => 'nullable|string|max:64',
            'context' => 'nullable|array',
        ]);

        $context = $validated['context'] ?? [];
        if (isset($validated['client_id']) && $validated['client_id'] !== null) {
            $context['client_id'] = $validated['client_id'];
        }
        if (!empty($validated['session_id'])) {
            $context['session_id'] = $validated['session_id'];
        }

        $this->audit->writeLocal($validated['level'], $validated['event'], $context);

        return response()->json(['status' => 'ok']);
    }
}
