<?php

namespace App\Services;

use App\Models\BusinessContext;
use App\Models\Client;
use App\Repositories\SchemaRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PythonProxyService
{
    private string $baseUrl;

    private string $clientKey;

    private string $adminKey;

    public function __construct(
        private SchemaRepository $schemaRepository,
        private UserAccessService $userAccess,
        private ClientSignatureService $signature,
        private AuditLogger $audit,
        ?string $baseUrl = null,
        ?string $clientKey = null,
        ?string $adminKey = null
    ) {
        $this->baseUrl = $baseUrl ?? config('python-proxy.base_url', 'http://127.0.0.1:8000');
        $this->clientKey = $clientKey ?? config('python-proxy.client_private_key', '');
        $this->adminKey = $adminKey ?? config('python-proxy.admin_private_key', '');
        if ($this->clientKey === '' && $this->adminKey === '') {
            throw new \RuntimeException(
                'No instance signing key configured. Set CLIENT_PRIVATE_KEY (client) '
                . 'or ADMIN_PRIVATE_KEY (admin) in .env.'
            );
        }
    }

    public function analyze(string $query, string $sessionId, Client $client, ?int $userId = null, ?string $userName = null): StreamedResponse
    {
        $merged = $this->schemaRepository->buildClientSchema($client);

        $dataPlaneUrl = config('python-proxy.data_plane_self_url', url('/api/internal/data/v1'));

        $traceId = Str::uuid()->toString();

        $userAccess = null;

        if ($userId !== null) {
            $user = \App\Models\User::find($userId);
            if ($user !== null && (int) $user->client_id === (int) $client->id) {
                $allowed = $this->userAccess->allowedTables($user);
                $allowedColumns = $this->userAccess->allowedColumns($user);
                $userAccess = [
                    'role' => (int) ($user->permissions['role'] ?? 0),
                    'tokens' => array_values((array) ($user->permissions['permissions'] ?? [])),
                    'allowed_tables' => $allowed,
                    // Per-user PMS permission column allow-list (distinct from the
                    // global sensitive_rules below). {table: [cols]}; a table is
                    // absent when unrestricted, null for admins.
                    'allowed_columns' => $allowedColumns,
                ];
            }
        }

        $payload = [
            'query' => $query,
            'session_id' => $sessionId,
            'client_id' => (string) $client->id,
            'datasource_id' => $client->id . '.analytics',
            'schema_version' => now()->toIso8601String(),
            'schema' => $merged['tables'],
            'user_ref' => $userId,
            'user_access' => $userAccess,
            'sensitive_rules' => [
                // Global privacy rules only — apply to every user, admin included.
                // The per-user permission allow-list travels separately via
                // `user_access.allowed_tables`; the agent derives the deny-set
                // from it. A grant can never override a sensitive table.
                'blocked_tables' => $merged['sensitive_tables'],
                'blocked_columns' => $merged['sensitive_columns'],
            ],
            'execution' => [
                'max_rows_per_query' => 10000,
                'max_query_time_ms' => config('python-proxy.query_timeout_ms', 30000),
            ],
            'agent_style' => array_merge(
                $client->agent_style ?? [],
                ['business_context' => BusinessContext::where('is_active', true)->orderBy('title')->get()->toArray()]
            ),
            // This instance's data-plane URL. Python calls back here for SQL.
            'data_plane_url' => $dataPlaneUrl,
            // Turn-completion URL. Python POSTs the turn's usage deltas here
            // when the turn finishes — the authoritative billing signal,
            // decoupled from the SSE relay (a cut stream can't drop billing).
            'completion_url' => rtrim($dataPlaneUrl, '/') . '/turn-complete',
            // End-to-end trace id, forwarded to Python and back.
            'trace_id' => $traceId,
            // Delegation token: authorizes Python to call the data-plane on
            // behalf of this client during this session. Signed with this
            // instance's client keypair; verified against clients.public_key.
            'delegation_token' => $this->signature->createDelegation(
                (string) $client->id,
                $sessionId,
                $this->clientKey,
                (int) config('python-proxy.delegation_ttl', 3600)
            ),
        ];

        $this->audit->info('api.analyze.request', [
            'client_id' => $client->id,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'trace_id' => $traceId,
        ]);

        return $this->streamToClient('post', '/internal/analyze', $payload, $traceId, (string) $client->id);
    }

    public function forwardGet(string $path): array
    {
        $start = microtime(true);
        $response = Http::withHeaders($this->signedHeaders('', $path))->get("{$this->baseUrl}{$path}");
        $response->throw();
        Log::debug('Proxy GET', ['path' => $path, 'duration_ms' => (microtime(true) - $start) * 1000]);

        return $response->json();
    }

    public function forwardPost(string $path, array $data = []): array
    {
        $start = microtime(true);
        $body = empty($data) ? '{}' : json_encode($data);
        $response = Http::withHeaders($this->signedHeaders($body, $path))
            ->withBody($body, 'application/json')
            ->post("{$this->baseUrl}{$path}");
        $response->throw();
        Log::debug('Proxy POST', ['path' => $path, 'duration_ms' => (microtime(true) - $start) * 1000]);

        return $response->json();
    }

    public function forwardGetRaw(string $path): \Illuminate\Http\Client\Response
    {
        $start = microtime(true);
        $response = Http::withHeaders($this->signedHeaders('', $path))->get("{$this->baseUrl}{$path}");
        Log::debug('Proxy GET raw', ['path' => $path, 'duration_ms' => (microtime(true) - $start) * 1000]);
        return $response;
    }

    /**
     * Headers for a signed request (Ed25519). Picks the signing key by path:
     * admin key management endpoints (/admin/keys/*) are signed with the admin
     * key; everything else (analyze) with the client key. Python verifies each
     * against the matching public key (ADMIN_PUBLIC_KEY vs clients.public_key).
     */
    private function signedHeaders(string $body, string $path = ''): array
    {
        ['timestamp' => $ts, 'signature' => $sig] = $this->signature->sign($body, $this->keyForPath($path));

        return array_filter([
            'X-Timestamp' => (string) $ts,
            'X-Signature' => $sig,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Select the signing key for a given Python path. Admin key-management
     * endpoints use the admin key; all other calls use the client key.
     */
    private function keyForPath(string $path): string
    {
        if (str_starts_with($path, '/admin/keys') && $this->adminKey !== '') {
            return $this->adminKey;
        }

        return $this->clientKey;
    }

    /**
     * Build an SSE error frame from a structured {code, message, detail}.
     */
    private function sseError(string $code, string $message, string $detail = ''): string
    {
        $payload = [
            'error' => $code,
            'message' => $message,
            'retryable' => true,
        ];
        if ($detail !== '') {
            $payload['detail'] = $detail;
        }
        return "event: error\ndata: " . json_encode($payload) . "\n\n";
    }

    private function streamToClient(string $method, string $path, array $payload, ?string $traceId = null, ?string $clientId = null): StreamedResponse
    {
        return response()->stream(function () use ($method, $path, $payload, $traceId, $clientId) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            @set_time_limit(0);
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            @ini_set('zlib.output_compression', '0');
            ob_implicit_flush(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            if (function_exists('apache_setenv')) {
                apache_setenv('no-gzip', '1');
            }

            echo ':' . str_repeat(' ', 8192) . "\n\n";
            flush();

            $parsed = parse_url($this->baseUrl);
            $host = $parsed['host'] ?? '127.0.0.1';
            $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
            $transport = $parsed['scheme'] === 'https' ? 'tls' : 'tcp';
            $connectTimeout = config('python-proxy.socket_connect_timeout', 5);

            $socket = @stream_socket_client(
                "{$transport}://{$host}:{$port}",
                $errno,
                $errstr,
                $connectTimeout
            );

            if (!$socket) {
                Log::error('Python upstream connect failed', ['client_id' => $clientId, 'trace_id' => $traceId, 'errstr' => $errstr]);
                $this->audit->error('error.proxy.unreachable', [
                    'client_id' => $clientId,
                    'trace_id' => $traceId,
                    'detail' => $errstr,
                ]);
                echo $this->sseError(
                    'PYTHON_UNREACHABLE',
                    "We couldn't reach the analytics service. Please try again in a moment.",
                    "Cannot connect to Python service: {$errstr}",
                );
                flush();
                return;
            }

            stream_set_read_buffer($socket, 0);
            $readTimeout = config('python-proxy.stream_timeout', 30);
            stream_set_timeout($socket, $readTimeout);

            $idleCut = false;
            $emittedError = false;

            try {
                $jsonBody = json_encode($payload);
                // Sign this instance's analyze request to Python with its client key.
                ['timestamp' => $ts, 'signature' => $sig] = $this->signature->sign($jsonBody, $this->clientKey);
                $request = strtoupper($method) . " {$path} HTTP/1.1\r\n"
                    . "Host: {$host}:{$port}\r\n"
                    . "Content-Type: application/json\r\n"
                    . "X-Timestamp: {$ts}\r\n"
                    . "X-Signature: {$sig}\r\n"
                    . ($traceId !== null ? "X-Trace-Id: {$traceId}\r\n" : '')
                    . "Content-Length: " . strlen($jsonBody) . "\r\n"
                    . "Connection: close\r\n"
                    . "\r\n"
                    . $jsonBody;

                fwrite($socket, $request);

                // Read and inspect Python's status line — never blind-relay a
                // non-2xx body (e.g. {"detail":"Invalid signature"}) that the
                // SSE client would silently drop.
                $statusLine = fgets($socket);
                $status = 200;
                if (is_string($statusLine) && preg_match('#HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $m)) {
                    $status = (int) $m[1];
                }

                while (!feof($socket)) {
                    $line = fgets($socket);
                    if ($line === false) break;
                    if ($line === "\r\n") break;
                }

                if ($status >= 400) {
                    $body = '';
                    while (!feof($socket)) {
                        $chunk = fread($socket, 1024);
                        if ($chunk === false) break;
                        $body .= $chunk;
                    }
                    Log::error('Python upstream non-2xx', [
                        'client_id' => $clientId,
                        'trace_id' => $traceId,
                        'status' => $status,
                        'body' => substr($body, 0, 500),
                    ]);
                    $this->audit->error('error.proxy.upstream', [
                        'client_id' => $clientId,
                        'trace_id' => $traceId,
                        'status' => $status,
                        'detail' => substr($body, 0, 500),
                    ]);
                    $detail = $body;
                    $decoded = json_decode($body, true);
                    if (is_array($decoded) && isset($decoded['detail'])) {
                        $detail = (string) $decoded['detail'];
                    }
                    $emittedError = true;
                    echo $this->sseError(
                        'PYTHON_UPSTREAM',
                        'There was a problem connecting to the analytics service. Please try again in a moment.',
                        $detail,
                    );
                    flush();
                    return;
                }

                $idleCount = 0;
                $idleLimit = max((int) config('python-proxy.stream_timeout', 30) * 10, 600);

                while (!connection_aborted()) {
                    $read = [$socket];
                    $w = null;
                    $e = null;
                    $selectResult = @stream_select($read, $w, $e, 0, 100_000);

                    if ($selectResult === false) break;

                    if ($selectResult > 0) {
                        $chunk = @fread($socket, 256);
                        if ($chunk === false) break;
                        if ($chunk !== '') {
                            echo $chunk;
                            flush();
                            $idleCount = 0;
                            continue;
                        }
                        if (@feof($socket)) break;
                    }

                    if (++$idleCount >= $idleLimit) {
                        $idleCut = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Python upstream stream exception', ['client_id' => $clientId, 'trace_id' => $traceId, 'error' => $e->getMessage()]);
                $this->audit->error('error.proxy.stream', [
                    'client_id' => $clientId,
                    'trace_id' => $traceId,
                    'detail' => $e->getMessage(),
                ]);
                $emittedError = true;
                echo $this->sseError(
                    'PROXY_ERROR',
                    'Something went wrong while processing your question. Please try again.',
                    $e->getMessage(),
                );
                flush();
            } finally {
                if (isset($socket) && is_resource($socket)) {
                    fclose($socket);
                }
                if (!connection_aborted() && !$emittedError) {
                    if ($idleCut) {
                        $emittedError = true;
                        Log::warning('Python upstream stream idle-cut', ['client_id' => $clientId, 'trace_id' => $traceId]);
                        $this->audit->error('error.proxy.timeout', [
                            'client_id' => $clientId,
                            'trace_id' => $traceId,
                        ]);
                        echo $this->sseError(
                            'PROXY_TIMEOUT',
                            'The analysis took too long and was stopped. Please try again, or try a shorter question.',
                        );
                    } else {
                        echo "event: done\ndata: {}\n\n";
                    }
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
