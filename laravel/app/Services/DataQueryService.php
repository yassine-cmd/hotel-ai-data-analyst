<?php

namespace App\Services;

use App\Models\Client;
use App\Services\ReadOnlyUserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class DataQueryService
{
    public function __construct(private ReadOnlyUserService $readOnly)
    {
    }

    public function execute(string $datasourceId, string $sql, int $maxRows, int $timeoutMs, string $queryId): array
    {
        $clientId = (int) str_replace('.analytics', '', $datasourceId);
        $client = Client::find($clientId);

        if (!$client) {
            throw new DataQueryException(
                'DATASOURCE_NOT_FOUND',
                "Datasource not found: $datasourceId",
                false,
                $queryId,
            );
        }

        // First time we touch this client's analytics DB, make sure the
        // read-only agent user exists (auto-create/recreate once). This is the
        // "on startup" hook the data-plane relies on; it is cached per client
        // so it does not run on every request.
        $this->readOnly->ensureClientAgentUserIfNeeded($client);

        $timeoutSecs = min(max((int)($timeoutMs / 1000), 1), 30);
        $connName = 'dataplane_' . $client->id;

        // Building the connection array dereferences $client->decrypted_password,
        // which can throw if the stored credential ciphertext no longer matches
        // CLIENT_CREDENTIALS_KEY. Keep that inside the try so such a config
        // failure surfaces as a clean, retryable DB_CONNECTION_FAILED and never
        // a raw 500.
        try {
            config([
                "database.connections.$connName" => [
                    'driver' => 'mysql',
                    'host' => $client->analytics_db_host,
                    'port' => (int) $client->analytics_db_port,
                    'database' => $client->analytics_db_name,
                    'username' => $client->analytics_db_user,
                    'password' => $client->decrypted_password,
                    'charset' => 'utf8mb4',
                    'prefix' => '',
                    'strict' => true,
                    'timeout' => $timeoutSecs,
                    'options' => [
                        PDO::ATTR_TIMEOUT => $timeoutSecs,
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ],
                ],
            ]);

            $pdo = DB::connection($connName)->getPdo();
        } catch (\Throwable $e) {
            $message = $e instanceof \Illuminate\Contracts\Encryption\DecryptException
                ? 'Client analytics DB credentials cannot be decrypted (CLIENT_CREDENTIALS_KEY mismatch). Re-save the client\'s DB passwords with `php artisan clients:set-credentials`.'
                : 'Cannot connect to analytics database: ' . $e->getMessage();

            throw new DataQueryException(
                'DB_CONNECTION_FAILED',
                $message,
                true,
                $queryId,
                $e,
            );
        }

        $this->configureTimeout($pdo, $client, $datasourceId, $timeoutMs);

        $fetchCap = $maxRows > 0 ? $maxRows + 1 : 10001;
        $sqlTrimmed = trim($sql);
        $wrappedSql = "SELECT * FROM ({$sqlTrimmed}) AS _data_plane_sub LIMIT {$fetchCap}";

        try {
            $stmt = $pdo->prepare($wrappedSql);
            $stmt->execute();

            $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rowCount = count($allRows);
            $truncated = false;

            if ($maxRows > 0 && $rowCount > $maxRows) {
                $allRows = array_slice($allRows, 0, $maxRows);
                $truncated = true;
            }

            $columns = [];
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = [
                    'name' => $meta['name'],
                    'db_type' => strtoupper($meta['driver:decl_type'] ?? $meta['native_type'] ?? 'VARCHAR'),
                    'nullable' => !in_array('not_null', $meta['flags'] ?? [], true),
                ];
            }

            $rows = [];
            foreach ($allRows as $row) {
                $rowArray = [];
                foreach ($row as $val) {
                    $rowArray[] = $val === null ? null : (string) $val;
                }
                $rows[] = $rowArray;
            }

            return [
                'query_id' => $queryId,
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => $rowCount,
                'truncated' => $truncated,
                'warnings' => [],
            ];
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $isTimeout = str_contains($msg, 'timed out') || str_contains($msg, 'max execution time');

            throw new DataQueryException(
                $isTimeout ? 'QUERY_TIMEOUT' : 'UPSTREAM_DB_ERROR',
                $isTimeout ? "Query exceeded {$timeoutMs} ms" : $msg,
                !$isTimeout,
                $queryId,
                $e,
            );
        } finally {
            DB::purge($connName);
        }
    }

    private function configureTimeout(PDO $pdo, Client $client, string $datasourceId, int $timeoutMs): void
    {
        $attemptedMaxExecution = false;
        $attemptedMaxStatement = false;

        try {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME = ' . (min($timeoutMs, 30000) * 1000));
            $attemptedMaxExecution = true;
        } catch (\Exception $e) {
            Log::warning('MAX_EXECUTION_TIME not supported', [
                'datasource' => $datasourceId,
                'host' => $client->analytics_db_host,
            ]);
        }

        try {
            $pdo->exec('SET SESSION max_statement_time = ' . (int) ceil($timeoutMs / 1000));
            $attemptedMaxStatement = true;
        } catch (\Exception $e) {
            Log::warning('max_statement_time not supported', [
                'datasource' => $datasourceId,
                'host' => $client->analytics_db_host,
            ]);
        }

        if (!$attemptedMaxExecution && !$attemptedMaxStatement) {
            Log::warning(
                'Neither MAX_EXECUTION_TIME nor max_statement_time is supported on {host}:{port}. '
                . 'Queries may run without server-side timeout enforcement.',
                ['host' => $client->analytics_db_host, 'port' => $client->analytics_db_port],
            );
        }
    }
}
