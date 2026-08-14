<?php

namespace Tests\Feature\Controllers;

use App\Models\Client;
use App\Models\PermissionToken;
use App\Models\SessionMetadata;
use App\Models\TokenUsage;
use App\Models\User;
use App\Repositories\SchemaRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SignsRequests;

class InternalDataControllerTest extends TestCase
{
    use SignsRequests;

    private Client $client;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createClient();
        $this->adminUser = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 1, 'permissions' => []],
        ]);

        SessionMetadata::create([
            'session_id' => 'test-session',
            'client_id' => $this->client->id,
            'user_id' => $this->adminUser->id,
            'name' => 'test',
            'path' => "{$this->client->id}/test-session/",
        ]);

        $this->setUpSigning();

        $connName = 'dataplane_' . $this->client->id;
        config([
            "database.connections.$connName" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::connection($connName)->getPdo()->exec('CREATE TABLE _dp_test (id INTEGER, label TEXT)');
        DB::connection($connName)->getPdo()->exec("INSERT INTO _dp_test VALUES (1, 'Alpha')");
        DB::connection($connName)->getPdo()->exec("INSERT INTO _dp_test VALUES (2, 'Beta')");
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'datasource_id' => "{$this->client->id}.analytics",
            'sql' => 'SELECT id, label FROM _dp_test ORDER BY id',
            'user_ref' => $this->adminUser->id,
            'referenced_tables' => ['_dp_test'],
        ], $overrides);
    }

    // --- delegation token auth ---

    public function test_query_rejects_missing_delegation_token(): void
    {
        $response = $this->postJson('/api/internal/data/v1/query', $this->validPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCESS_DENIED');
        $response->assertJsonPath('error.message', 'Missing delegation token');
    }

    public function test_query_rejects_invalid_delegation_token(): void
    {
        $response = $this->postDelegatedJsonBadToken('/api/internal/data/v1/query', $this->validPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCESS_DENIED');
        $response->assertJsonPath('error.message', 'Invalid delegation token');
    }

    public function test_query_accepts_valid_delegation_token(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload());

        $response->assertOk();
    }

    public function test_query_rejects_delegation_for_inactive_session(): void
    {
        $payload = array_merge($this->validPayload(), ['session_id' => 'revoked-session']);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $payload);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
        $response->assertJsonPath('error.message', 'Session is not active');
    }

    public function test_query_returns_502_when_credentials_cannot_be_decrypted(): void
    {
        // Simulate a total CLIENT_CREDENTIALS_KEY loss: corrupt BOTH stored
        // passwords so the system cannot auto-recover the read-only user via
        // the admin credential either. Must be a clean 502 (not a raw 500) so
        // the agent gets an actionable error. (Corrupting only the agent
        // password would be auto-healed by ReadOnlyUserService, which
        // re-provisions the user on the next data-plane connection.)
        $this->client->forceFill([
            'analytics_agent_password' => 'not-a-valid-ciphertext',
            'analytics_admin_password' => 'not-a-valid-ciphertext',
        ])->save();
        $this->client->refresh();

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload());

        $response->assertStatus(502);
        $response->assertJsonPath('error.code', 'DB_CONNECTION_FAILED');
    }

    // --- turn-complete ---

    private function turnPayload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'session_id' => 'test-session',
            'user_ref' => $this->adminUser->id,
            'turn_uuid' => 'turn-xyz',
            'usage' => [
                'turn_tokens' => 100,
                'turn_prompt_tokens' => 60,
                'turn_completion_tokens' => 40,
                'turn_reasoning_tokens' => 10,
                'turn_cache_hit_tokens' => 0,
                'turn_cache_miss_tokens' => 60,
                'turn_cost_usd' => 0.01,
            ],
        ], $overrides);
    }

    private function postTurnComplete(array $data, array $headers = [])
    {
        $token = $this->signer->createDelegation(
            (string) $this->client->id,
            $data['session_id'],
            $this->testPrivateKey,
            7200
        );

        return $this->withHeaders(array_merge($headers, [
            'X-Delegation-Token' => $token,
        ]))->postJson('/api/internal/data/v1/turn-complete', $data);
    }

    public function test_turn_complete_rejects_missing_token(): void
    {
        $response = $this->postJson('/api/internal/data/v1/turn-complete', $this->turnPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_turn_complete_rejects_invalid_token(): void
    {
        $response = $this->withHeaders([
            'X-Delegation-Token' => 'invalid.token',
        ])->postJson('/api/internal/data/v1/turn-complete', $this->turnPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_turn_complete_rejects_session_mismatch(): void
    {
        // Token signed for 'test-session' but body claims 'other-session'.
        $token = $this->signer->createDelegation(
            (string) $this->client->id,
            'test-session',
            $this->testPrivateKey,
            7200
        );

        $response = $this->withHeaders([
            'X-Delegation-Token' => $token,
        ])->postJson('/api/internal/data/v1/turn-complete', $this->turnPayload(['session_id' => 'other-session']));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'ACCESS_DENIED');
    }

    public function test_turn_complete_records_usage(): void
    {
        $response = $this->postTurnComplete($this->turnPayload());

        $response->assertOk();
        $response->assertJsonPath('turn_uuid', 'turn-xyz');

        $meta = SessionMetadata::where('session_id', 'test-session')->where('client_id', $this->client->id)->first();
        $this->assertSame(100, $meta->total_tokens);
        $this->assertSame(60, $meta->prompt_tokens);

        $row = TokenUsage::where('session_id', 'test-session')->where('client_id', $this->client->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('turn-xyz', $row->turn_uuid);
        $this->assertSame(100, $row->total_tokens);
        // The billing ledger row must carry an explicit UTC timestamp so
        // monthly cost windows (now()->startOfMonth()) attribute it correctly,
        // independent of the MySQL server's timezone.
        $this->assertTrue($row->created_at->diffInSeconds(now()) < 5, 'token_usage.created_at must be stamped now() (UTC)');
    }

    public function test_turn_complete_is_idempotent_per_turn_uuid(): void
    {
        $this->postTurnComplete($this->turnPayload());
        $response = $this->postTurnComplete($this->turnPayload());

        $response->assertOk();
        $this->assertSame(1, TokenUsage::where('session_id', 'test-session')->where('client_id', $this->client->id)->count());

        $meta = SessionMetadata::where('session_id', 'test-session')->where('client_id', $this->client->id)->first();
        $this->assertSame(100, $meta->total_tokens);
    }

    // --- SQL validation ---

    public function test_query_rejects_non_select(): void
    {
        foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'CREATE', 'ALTER'] as $stmt) {
            $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
                'sql' => "$stmt TABLE users",
            ]));

            $response->assertStatus(400);
            $response->assertJsonPath('error.code', 'INVALID_SQL');
        }
    }

    public function test_query_accepts_select(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'SELECT * FROM _dp_test',
        ]));

        $this->assertNotEquals(400, $response->getStatusCode());
    }

    public function test_query_accepts_with_cte(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'WITH cte AS (SELECT 1 AS x) SELECT * FROM cte',
        ]));

        $this->assertNotEquals(400, $response->getStatusCode());
    }

    public function test_query_rejects_into_outfile(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => "SELECT * FROM users INTO OUTFILE '/tmp/dump.csv'",
        ]));

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'INVALID_SQL');
        $response->assertJsonPath('error.message', 'INTO OUTFILE/DUMPFILE is not allowed');
    }

    public function test_query_rejects_into_dumpfile(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => "SELECT * FROM users INTO DUMPFILE '/tmp/dump'",
        ]));

        $response->assertStatus(400);
    }

    public function test_query_rejects_multi_statement_dml(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'SELECT 1; DROP TABLE users',
        ]));

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'INVALID_SQL');
    }

    // --- datasource lookup ---

    public function test_query_rejects_unknown_datasource(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'datasource_id' => 'nonexistent.analytics',
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'DATASOURCE_NOT_FOUND');
    }

    // --- validation ---

    public function test_query_validates_required_fields(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['datasource_id', 'sql']);
    }

    public function test_query_validates_max_rows_range(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'max_rows' => 200000,
        ]));

        $response->assertStatus(422);
    }

    // --- successful query execution ---

    public function test_query_success_with_results(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload());

        $response->assertOk();
        $response->assertJsonPath('row_count', 2);
        $response->assertJsonCount(2, 'rows');
        $this->assertFalse($response->json('truncated'));
    }

    public function test_query_empty_result(): void
    {
        $connName = 'dataplane_' . $this->client->id;
        DB::connection($connName)->getPdo()->exec('CREATE TABLE _dp_empty (v INTEGER)');

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'SELECT v FROM _dp_empty',
        ]));

        $response->assertOk();
        $response->assertJsonPath('row_count', 0);
        $response->assertJsonCount(0, 'rows');
    }

    public function test_query_truncation(): void
    {
        $connName = 'dataplane_' . $this->client->id;
        $pdo = DB::connection($connName)->getPdo();
        $pdo->exec('CREATE TABLE _dp_many (v INTEGER)');
        for ($i = 1; $i <= 5; $i++) {
            $pdo->exec("INSERT INTO _dp_many VALUES ($i)");
        }

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'SELECT v FROM _dp_many ORDER BY v',
            'max_rows' => 2,
        ]));

        $response->assertOk();
        $response->assertJsonPath('row_count', 3);
        $response->assertJsonPath('truncated', true);
        $this->assertCount(2, $response->json('rows'));
    }

    public function test_query_returns_column_metadata(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'SELECT * FROM _dp_test',
        ]));

        $response->assertOk();
        $columns = $response->json('columns');
        $this->assertCount(2, $columns);
        $this->assertSame('id', $columns[0]['name']);
        $this->assertSame('label', $columns[1]['name']);
    }

    public function test_query_preserves_column_types(): void
    {
        $connName = 'dataplane_' . $this->client->id;
        $pdo = DB::connection($connName)->getPdo();
        $pdo->exec('CREATE TABLE _dp_types (i INTEGER, r REAL, t TEXT)');
        $pdo->exec("INSERT INTO _dp_types VALUES (42, 3.14, 'hello')");

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'sql' => 'SELECT * FROM _dp_types',
        ]));

        $response->assertOk();
        $columns = $response->json('columns');
        $this->assertNotEmpty($columns[0]['name']);
        $this->assertNotEmpty($columns[0]['db_type']);
    }

    // --- per-user permission whitelist ---

    public function test_query_rejects_missing_user_ref(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'user_ref' => null,
            'referenced_tables' => ['_dp_test'],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_ref']);
    }

    public function test_query_rejects_unknown_user(): void
    {
        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'user_ref' => 999999,
            'referenced_tables' => ['_dp_test'],
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'USER_NOT_FOUND');
    }

    public function test_query_rejects_ungranted_table_for_non_admin(): void
    {
        $user = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 0, 'permissions' => ['CAISSE']],
        ]);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'user_ref' => $user->id,
            'referenced_tables' => ['_dp_test'],
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TABLE_NOT_ALLOWED');
    }

    public function test_query_allows_granted_table_for_non_admin(): void
    {
        PermissionToken::create([
            'code' => 'TEST',
            'name' => 'Test',
            'grants' => ['tables' => ['_dp_test' => '*']],
            'is_active' => true,
        ]);

        $user = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 0, 'permissions' => ['TEST']],
        ]);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'user_ref' => $user->id,
            'referenced_tables' => ['_dp_test'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('row_count', 2);
    }

    // --- global sensitive rules (apply to every user, admins included) ---

    private function mockSensitiveTables(array $tables): void
    {
        $this->mock(SchemaRepository::class)
            ->shouldReceive('sensitiveTables')
            ->andReturn($tables);
    }

    public function test_query_rejects_sensitive_table_even_for_admin(): void
    {
        $this->mockSensitiveTables(['_dp_sensitive']);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'referenced_tables' => ['_dp_sensitive'],
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TABLE_SENSITIVE');
    }

    public function test_query_sensitive_wins_over_permission(): void
    {
        $this->mockSensitiveTables(['_dp_sensitive']);

        $user = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 0, 'permissions' => ['CAISSE']],
        ]);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'user_ref' => $user->id,
            'referenced_tables' => ['_dp_sensitive'],
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TABLE_SENSITIVE');
    }

    public function test_query_sensitive_denies_granted_table(): void
    {
        $this->mockSensitiveTables(['_dp_sensitive']);
        PermissionToken::create([
            'code' => 'TRY',
            'name' => 'Try',
            'grants' => ['tables' => ['_dp_sensitive' => '*']],
            'is_active' => true,
        ]);

        $user = $this->createUser([
            'client_id' => $this->client->id,
            'permissions' => ['role' => 0, 'permissions' => ['TRY']],
        ]);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'user_ref' => $user->id,
            'referenced_tables' => ['_dp_sensitive'],
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TABLE_SENSITIVE');
    }

    public function test_query_allows_nonsensitive_granted_table_for_admin(): void
    {
        $this->mockSensitiveTables([]);

        $response = $this->postDelegatedJson('/api/internal/data/v1/query', $this->validPayload([
            'referenced_tables' => ['_dp_test'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('row_count', 2);
    }
}
