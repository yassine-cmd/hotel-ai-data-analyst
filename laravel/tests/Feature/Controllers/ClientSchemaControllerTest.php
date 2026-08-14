<?php

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Repositories\SchemaRepository;
use App\Services\SuggestionService;
use Mockery;
use Tests\TestCase;

class ClientSchemaControllerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->createClient();
        $this->user = $this->createUser([
            'client_id' => $client->id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(SchemaRepository::class);
        $this->app->forgetInstance(SuggestionService::class);
        parent::tearDown();
    }

    public function test_show_returns_schema_for_authenticated_client(): void
    {
        $this->actingAs($this->user);

        $mockSchema = [
            'tables' => [
                'users' => ['row_count' => 100, 'columns' => [], 'foreign_keys' => [], 'description' => null],
            ],
            'sensitive_tables' => [],
            'sensitive_columns' => ['*' => []],
        ];

        $mockRepo = Mockery::mock(SchemaRepository::class);
        $mockRepo->shouldReceive('buildClientSchema')->andReturn($mockSchema);
        $this->app->instance(SchemaRepository::class, $mockRepo);

        $mockSuggestions = Mockery::mock(SuggestionService::class);
        $mockSuggestions->shouldReceive('generate')->andReturn([]);
        $this->app->instance(SuggestionService::class, $mockSuggestions);

        $response = $this->getJson('/api/client/schema');

        $response->assertOk();
        $response->assertJsonPath('tables.users.row_count', 100);
        $response->assertJsonPath('sensitive_tables', []);
        $response->assertJsonStructure(['tables', 'sensitive_tables', 'sensitive_columns', 'suggestions']);
    }

    public function test_show_returns_sensitive_rules(): void
    {
        $this->actingAs($this->user);

        $mockRepo = Mockery::mock(SchemaRepository::class);
        $mockRepo->shouldReceive('buildClientSchema')->andReturn([
            'tables' => [],
            'sensitive_tables' => ['employee_records'],
            'sensitive_columns' => ['*' => ['ssn']],
        ]);
        $this->app->instance(SchemaRepository::class, $mockRepo);

        $mockSuggestions = Mockery::mock(SuggestionService::class);
        $mockSuggestions->shouldReceive('generate')->andReturn([]);
        $this->app->instance(SuggestionService::class, $mockSuggestions);

        $response = $this->getJson('/api/client/schema');

        $response->assertOk();
        $response->assertJsonPath('sensitive_tables', ['employee_records']);
        $response->assertJsonPath('sensitive_columns.*', [['ssn']]);
        $response->assertJsonStructure(['tables', 'sensitive_tables', 'sensitive_columns', 'suggestions']);
    }

    public function test_show_returns_empty_when_user_has_no_client(): void
    {
        $orphanUser = $this->createUser(['client_id' => null]);
        $this->actingAs($orphanUser);

        $this->getJson('/api/client/schema')->assertForbidden();
    }

    public function test_show_requires_authentication(): void
    {
        $response = $this->getJson('/api/client/schema');

        $response->assertStatus(401);
    }

    public function test_show_client_user_ignores_input_client_id(): void
    {
        $this->actingAs($this->user);

        $mockSchema = [
            'tables' => [
                'reservations' => ['row_count' => 50, 'columns' => [], 'foreign_keys' => [], 'description' => null],
            ],
            'sensitive_tables' => [],
            'sensitive_columns' => ['*' => []],
        ];

        $mockRepo = Mockery::mock(SchemaRepository::class);
        $mockRepo->shouldReceive('buildClientSchema')
            ->once()
            ->andReturn($mockSchema);
        $this->app->instance(SchemaRepository::class, $mockRepo);

        $mockSuggestions = Mockery::mock(SuggestionService::class);
        $mockSuggestions->shouldReceive('generate')->andReturn([]);
        $this->app->instance(SuggestionService::class, $mockSuggestions);

        $response = $this->getJson('/api/client/schema?client_id=999');

        $response->assertOk();
        $response->assertJsonPath('tables.reservations.row_count', 50);
    }

    public function test_show_admin_with_input_client_id_resolves_schema(): void
    {
        $targetClient = $this->createClient();
        $adminUser = $this->createAdminUser();
        $this->actingAs($adminUser, 'admin');

        $this->getJson('/api/client/schema?client_id=' . $targetClient->id)->assertForbidden();
    }

    public function test_show_admin_without_input_client_id_returns_empty(): void
    {
        $adminUser = $this->createAdminUser();
        $this->actingAs($adminUser, 'admin');

        $this->getJson('/api/client/schema')->assertForbidden();
    }

    public function test_show_non_admin_without_client_cannot_override_via_input(): void
    {
        $otherClient = $this->createClient();
        $orphanUser = $this->createUser(['client_id' => null]);
        $this->actingAs($orphanUser);

        $this->getJson('/api/client/schema?client_id=' . $otherClient->id)->assertForbidden();
    }
}
