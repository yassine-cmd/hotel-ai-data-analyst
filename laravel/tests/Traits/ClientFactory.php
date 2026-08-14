<?php

namespace Tests\Traits;

use App\Models\Admin;
use App\Models\Client;
use App\Models\User;
use App\Support\ClientCredentialCipher;
use Tests\Support\TestInstanceKey;

trait ClientFactory
{
    protected function createClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Hotel',
            'public_key' => TestInstanceKey::PUBLIC,
            'analytics_db_dsn' => 'localhost:3306/test_db',
            'analytics_agent_user' => 'fms_agent',
            'analytics_agent_password' => ClientCredentialCipher::encrypt('test-password'),
            'analytics_admin_user' => 'root',
            'analytics_admin_password' => ClientCredentialCipher::encrypt('adminpass'),
            'agent_style' => ['language' => 'en', 'tone' => 'professional'],
            'is_active' => true,
        ], $overrides));
    }

    protected function createUser(array $overrides = []): User
    {
        $bin = bin2hex(random_bytes(4));
        return User::create(array_merge([
            'name' => 'Test User',
            'username' => 'testuser-' . $bin,
            'password' => bcrypt('password'),
        ], $overrides));
    }

    protected function createAdminUser(array $overrides = []): Admin
    {
        return Admin::create(array_merge([
            'name' => 'Test Admin',
            'username' => 'admin-' . bin2hex(random_bytes(4)),
            'password' => bcrypt('password'),
        ], $overrides));
    }
}
