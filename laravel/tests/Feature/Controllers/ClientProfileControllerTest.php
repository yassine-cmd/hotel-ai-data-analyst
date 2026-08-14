<?php

namespace Tests\Feature\Controllers;

use App\Models\Client;
use App\Models\User;
use Tests\TestCase;

class ClientProfileControllerTest extends TestCase
{
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createClient();
        $this->user = $this->createUser([
            'client_id' => $this->client->id,
        ]);
    }

    public function test_show_returns_profile_with_client(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/client/profile');

        $response->assertOk();
        $response->assertJsonPath('user.id', $this->user->id);
        $response->assertJsonPath('user.name', $this->user->name);
        $response->assertJsonPath('user.username', $this->user->username);
        $response->assertJsonPath('user.client_id', $this->client->id);
        $response->assertJsonPath('client.id', $this->client->id);
        $response->assertJsonPath('client.name', $this->client->name);
    }

    public function test_show_rejects_user_without_client(): void
    {
        $orphan = $this->createUser(['client_id' => null]);
        $this->actingAs($orphan);

        $response = $this->getJson('/api/client/profile');

        $response->assertForbidden();
    }

    public function test_show_rejects_unauthenticated(): void
    {
        $response = $this->getJson('/api/client/profile');

        $response->assertStatus(401);
    }
}
