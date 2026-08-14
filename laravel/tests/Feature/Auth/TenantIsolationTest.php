<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientSignatureService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    private ClientSignatureService $signature;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signature = new ClientSignatureService();
    }

    private function configureClientInstance(string $privateKey): void
    {
        Config::set('python-proxy.client_private_key', $privateKey);
        Config::set('python-proxy.admin_private_key', '');
    }

    private function configureAdminInstance(string $privateKey): void
    {
        Config::set('python-proxy.client_private_key', '');
        Config::set('python-proxy.admin_private_key', $privateKey);
    }

    private function configureDualRoleInstance(string $clientPrivateKey, string $adminPrivateKey): void
    {
        Config::set('python-proxy.client_private_key', $clientPrivateKey);
        Config::set('python-proxy.admin_private_key', $adminPrivateKey);
    }

    public function test_client_user_logs_into_own_instance(): void
    {
        $kp = $this->signature->generate();
        $client = $this->createClient(['public_key' => $kp['public_key']]);
        $this->createUser([
            'client_id' => $client->id,
            'username' => 'jane',
            'password' => bcrypt('pw'),
        ]);

        $this->configureClientInstance($kp['private_key']);

        $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'pw'])
            ->assertOk()
            ->assertJsonPath('user.client_id', $client->id)
            ->assertJsonPath('user.is_admin', false);
    }

    public function test_collision_routes_to_correct_tenant(): void
    {
        $kpA = $this->signature->generate();
        $kpB = $this->signature->generate();
        $clientA = $this->createClient(['public_key' => $kpA['public_key']]);
        $clientB = $this->createClient(['public_key' => $kpB['public_key']]);

        // Identical username + password in both tenants (the collision case).
        $this->createUser(['client_id' => $clientA->id, 'username' => 'jane', 'password' => bcrypt('pw')]);
        $this->createUser(['client_id' => $clientB->id, 'username' => 'jane', 'password' => bcrypt('pw')]);

        $this->configureClientInstance($kpA['private_key']);
        $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'pw'])
            ->assertOk()
            ->assertJsonPath('user.client_id', $clientA->id);

        $this->configureClientInstance($kpB['private_key']);
        $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'pw'])
            ->assertOk()
            ->assertJsonPath('user.client_id', $clientB->id);
    }

    public function test_foreign_unique_user_rejected_on_wrong_instance(): void
    {
        $kpA = $this->signature->generate();
        $kpB = $this->signature->generate();
        $clientA = $this->createClient(['public_key' => $kpA['public_key']]);
        $clientB = $this->createClient(['public_key' => $kpB['public_key']]);

        $this->createUser(['client_id' => $clientB->id, 'username' => 'bob', 'password' => bcrypt('pw')]);

        $this->configureClientInstance($kpA['private_key']);
        $this->postJson('/api/auth/login', ['username' => 'bob', 'password' => 'pw'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_admin_rejected_on_client_instance(): void
    {
        $kpB = $this->signature->generate();
        $clientB = $this->createClient(['public_key' => $kpB['public_key']]);
        $this->createUser(['client_id' => $clientB->id, 'username' => 'jane', 'password' => bcrypt('pw')]);
        $this->createAdminUser(['username' => 'superadmin', 'password' => bcrypt('pw')]);

        $this->configureClientInstance($kpB['private_key']);
        $this->postJson('/api/auth/login', ['username' => 'superadmin', 'password' => 'pw'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_admin_login_works_on_admin_instance(): void
    {
        $adminKp = $this->signature->generate();
        $admin = $this->createAdminUser(['username' => 'superadmin', 'password' => bcrypt('pw')]);

        $this->configureAdminInstance($adminKp['private_key']);
        $this->postJson('/api/auth/login', ['username' => 'superadmin', 'password' => 'pw'])
            ->assertOk()
            ->assertJsonPath('user.is_admin', true)
            ->assertJsonPath('user.client_id', null);
    }

    public function test_client_user_rejected_on_admin_instance(): void
    {
        $adminKp = $this->signature->generate();
        $this->createUser(['client_id' => $this->createClient()->id, 'username' => 'jane', 'password' => bcrypt('pw')]);

        $this->configureAdminInstance($adminKp['private_key']);
        $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'pw'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_client_route_rejects_wrong_tenant_token(): void
    {
        $kpB = $this->signature->generate();
        $clientB = $this->createClient(['public_key' => $kpB['public_key']]);
        $foreignUser = $this->createUser([
            'client_id' => $this->createClient()->id,
            'username' => 'alien',
            'password' => bcrypt('pw'),
        ]);

        $this->configureClientInstance($kpB['private_key']);
        $this->actingAs($foreignUser)
            ->getJson('/api/client/profile')
            ->assertForbidden();
    }

    public function test_client_route_allows_same_tenant_token(): void
    {
        $kpB = $this->signature->generate();
        $clientB = $this->createClient(['public_key' => $kpB['public_key']]);
        $user = $this->createUser([
            'client_id' => $clientB->id,
            'username' => 'insider',
            'password' => bcrypt('pw'),
        ]);

        $this->configureClientInstance($kpB['private_key']);
        $this->actingAs($user)
            ->getJson('/api/client/profile')
            ->assertOk();
    }

    public function test_unconfigured_instance_rejects_logins(): void
    {
        Config::set('python-proxy.client_private_key', '');
        Config::set('python-proxy.admin_private_key', '');
        $this->createUser(['client_id' => $this->createClient()->id, 'username' => 'legacy', 'password' => bcrypt('pw')]);

        $this->postJson('/api/auth/login', ['username' => 'legacy', 'password' => 'pw'])
            ->assertStatus(500);
    }

    public function test_dual_role_instance_allows_own_tenant_client_route(): void
    {
        $kp = $this->signature->generate();
        $client = $this->createClient(['public_key' => $kp['public_key']]);
        $user = $this->createUser([
            'client_id' => $client->id,
            'username' => 'insider',
            'password' => bcrypt('pw'),
        ]);

        $this->configureDualRoleInstance($kp['private_key'], $kp['private_key']);
        $this->actingAs($user)
            ->getJson('/api/client/profile')
            ->assertOk();
    }

    public function test_dual_role_instance_rejects_foreign_tenant_client_route(): void
    {
        $kp = $this->signature->generate();
        $client = $this->createClient(['public_key' => $kp['public_key']]);
        $foreignUser = $this->createUser([
            'client_id' => $this->createClient()->id,
            'username' => 'alien',
            'password' => bcrypt('pw'),
        ]);

        $this->configureDualRoleInstance($kp['private_key'], $kp['private_key']);
        $this->actingAs($foreignUser)
            ->getJson('/api/client/profile')
            ->assertForbidden();
    }

    public function test_client_route_rejected_on_admin_only_instance(): void
    {
        $adminKp = $this->signature->generate();
        $user = $this->createUser([
            'client_id' => $this->createClient()->id,
            'username' => 'jane',
            'password' => bcrypt('pw'),
        ]);

        $this->configureAdminInstance($adminKp['private_key']);
        $this->actingAs($user)
            ->getJson('/api/client/profile')
            ->assertForbidden();
    }

    public function test_dual_role_instance_allows_admin_and_client_login(): void
    {
        $kp = $this->signature->generate();
        $client = $this->createClient(['public_key' => $kp['public_key']]);
        $this->createAdminUser(['username' => 'superadmin', 'password' => bcrypt('pw')]);
        $this->createUser(['client_id' => $client->id, 'username' => 'jane', 'password' => bcrypt('pw')]);

        Config::set('python-proxy.client_private_key', $kp['private_key']);
        Config::set('python-proxy.admin_private_key', $kp['private_key']);

        $this->postJson('/api/auth/login', ['username' => 'superadmin', 'password' => 'pw'])
            ->assertOk()
            ->assertJsonPath('user.is_admin', true)
            ->assertJsonPath('user.client_id', null);

        $this->postJson('/api/auth/login', ['username' => 'jane', 'password' => 'pw'])
            ->assertOk()
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonPath('user.client_id', $client->id);
    }
}
