<?php

namespace Tests\Feature\Controllers;

use App\Models\Admin;
use Illuminate\Support\Facades\Config;
use Tests\Support\TestInstanceKey;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    public function test_login_success(): void
    {
        Config::set('python-proxy.client_private_key', '');
        Config::set('python-proxy.admin_private_key', TestInstanceKey::PRIVATE);

        Admin::create([
            'name' => 'Admin',
            'username' => 'admin',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.username', 'admin');
        $response->assertJsonPath('user.is_admin', true);
        $response->assertJsonPath('user.client_id', null);
    }

    public function test_login_with_client_user_returns_client_id(): void
    {
        $client = $this->createClient();

        $user = $this->createUser([
            'client_id' => $client->id,
            'username' => 'hoteluser',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'hoteluser',
            'password' => 'secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.client_id', $client->id);
        $this->assertIsInt($response->json('user.client_id'));
    }

    public function test_login_invalid_credentials(): void
    {
        $client = $this->createClient();
        $this->createUser([
            'client_id' => $client->id,
            'username' => 'testuser',
            'password' => bcrypt('correct'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'wrong',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_missing_username(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'password' => 'secret',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_returns_authenticated_user(): void
    {
        $user = $this->createUser([
            'name' => 'Current User',
            'username' => 'current-user',
        ]);

        $response = $this->actingAs($user)->getJson('/api/auth/user');

        $response->assertOk();
        $response->assertJsonPath('username', 'current-user');
        $response->assertJsonPath('name', 'Current User');
        $response->assertJsonPath('client_id', null);
    }

    public function test_user_with_client_returns_client_id(): void
    {
        $client = $this->createClient();

        $user = $this->createUser([
            'client_id' => $client->id,
            'name' => 'Client User',
            'username' => 'clientuser',
        ]);

        $response = $this->actingAs($user)->getJson('/api/auth/user');

        $response->assertOk();
        $response->assertJsonPath('client_id', $client->id);
        $this->assertIsInt($response->json('client_id'));
    }

    public function test_login_rejects_deactivated_client(): void
    {
        $client = $this->createClient(['is_active' => false]);

        $this->createUser([
            'client_id' => $client->id,
            'username' => 'hoteluser',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'hoteluser',
            'password' => 'secret',
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Your account has been deactivated. Contact an administrator.');
    }

    public function test_user_unauthenticated(): void
    {
        $response = $this->getJson('/api/auth/user');

        $response->assertStatus(401);
    }
}
