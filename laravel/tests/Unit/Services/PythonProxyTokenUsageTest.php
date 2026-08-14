<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\SessionMetadata;
use App\Models\TokenUsage;
use App\Models\User;
use App\Services\TokenUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ClientFactory;

class PythonProxyTokenUsageTest extends TestCase
{
    use ClientFactory;
    use RefreshDatabase;

    private TokenUsageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(TokenUsageService::class);
    }

    private function makeClient(): Client
    {
        return $this->createClient();
    }

    private function makeUser(int $id, string $name, Client $client): User
    {
        return $this->createUser([
            'name' => $name,
            'username' => "user-{$id}",
            'client_id' => $client->id,
        ]);
    }

    public function test_record_usage_creates_session_and_turn_rows(): void
    {
        $client = $this->makeClient();
        $user = $this->makeUser(7, 'Alice', $client);

        $this->service->recordUsage('sess-1', $client, $user->id, 'Alice', [
            'turn_tokens' => 100,
            'turn_prompt_tokens' => 60,
            'turn_cache_hit_tokens' => 20,
            'turn_cache_miss_tokens' => 40,
            'turn_completion_tokens' => 40,
            'turn_reasoning_tokens' => 10,
        ]);

        $meta = SessionMetadata::where('session_id', 'sess-1')->first();
        $this->assertNotNull($meta);
        $this->assertSame(100, $meta->total_tokens);
        $this->assertSame(60, $meta->prompt_tokens);
        $this->assertSame(40, $meta->completion_tokens);
        $this->assertSame(10, $meta->reasoning_tokens);
        $this->assertSame($user->id, $meta->user_id);
        $this->assertSame('Alice', $meta->user_name);

        $row = TokenUsage::where('session_id', 'sess-1')->first();
        $this->assertNotNull($row);
        $this->assertSame(100, $row->total_tokens);
        $this->assertSame(20, $row->cache_hit_tokens);
        $this->assertSame(40, $row->cache_miss_tokens);
        $this->assertSame($client->id, $row->client_id);
    }

    public function test_record_usage_accumulates_across_turns_and_user_sticks(): void
    {
        $client = $this->makeClient();
        $user = $this->makeUser(9, 'Bob', $client);

        $this->service->recordUsage('sess-2', $client, $user->id, 'Bob', [
            'turn_tokens' => 200,
            'turn_prompt_tokens' => 150,
            'turn_completion_tokens' => 50,
            'turn_reasoning_tokens' => 20,
        ]);

        $this->service->recordUsage('sess-2', $client, $user->id, 'Bob', [
            'turn_tokens' => 300,
            'turn_prompt_tokens' => 250,
            'turn_completion_tokens' => 50,
            'turn_reasoning_tokens' => 5,
        ]);

        $meta = SessionMetadata::where('session_id', 'sess-2')->first();
        $this->assertSame(500, $meta->total_tokens);
        $this->assertSame(400, $meta->prompt_tokens);
        $this->assertSame(100, $meta->completion_tokens);
        $this->assertSame(25, $meta->reasoning_tokens);
        $this->assertSame($user->id, $meta->user_id);
        $this->assertSame('Bob', $meta->user_name);

        $this->assertSame(2, TokenUsage::where('session_id', 'sess-2')->count());
        $this->assertSame(300, TokenUsage::where('session_id', 'sess-2')->latest('id')->first()->total_tokens);
    }

    public function test_record_usage_replaces_null_user_from_first_turn(): void
    {
        $client = $this->makeClient();
        $carol = $this->makeUser(3, 'Carol', $client);

        $this->service->recordUsage('sess-3', $client, null, null, [
            'turn_tokens' => 50,
            'turn_prompt_tokens' => 50,
            'turn_completion_tokens' => 0,
            'turn_reasoning_tokens' => 0,
        ]);

        $this->service->recordUsage('sess-3', $client, $carol->id, 'Carol', [
            'turn_tokens' => 25,
            'turn_prompt_tokens' => 10,
            'turn_completion_tokens' => 15,
            'turn_reasoning_tokens' => 0,
        ]);

        $meta = SessionMetadata::where('session_id', 'sess-3')->first();
        $this->assertSame(75, $meta->total_tokens);
        $this->assertSame($carol->id, $meta->user_id);
        $this->assertSame('Carol', $meta->user_name);
    }

    public function test_record_usage_skips_zero_turn(): void
    {
        $client = $this->makeClient();

        $this->service->recordUsage('sess-4', $client, null, null, [
            'turn_tokens' => 0,
            'turn_prompt_tokens' => 0,
            'turn_completion_tokens' => 0,
            'turn_reasoning_tokens' => 0,
        ]);

        $this->assertSame(0, SessionMetadata::where('session_id', 'sess-4')->count());
        $this->assertSame(0, TokenUsage::where('session_id', 'sess-4')->count());
    }

    public function test_record_usage_is_idempotent_per_turn_uuid(): void
    {
        $client = $this->makeClient();
        $user = $this->makeUser(11, 'Dana', $client);

        $meta = [
            'turn_tokens' => 100,
            'turn_prompt_tokens' => 60,
            'turn_completion_tokens' => 40,
            'turn_reasoning_tokens' => 10,
        ];

        $this->service->recordUsage('sess-5', $client, $user->id, 'Dana', $meta, 'turn-abc');
        $this->service->recordUsage('sess-5', $client, $user->id, 'Dana', $meta, 'turn-abc');

        $this->assertSame(1, TokenUsage::where('session_id', 'sess-5')->count());
        $this->assertSame('turn-abc', TokenUsage::where('session_id', 'sess-5')->first()->turn_uuid);

        $metaRow = SessionMetadata::where('session_id', 'sess-5')->first();
        $this->assertSame(100, $metaRow->total_tokens);
        $this->assertSame(60, $metaRow->prompt_tokens);
    }
}
