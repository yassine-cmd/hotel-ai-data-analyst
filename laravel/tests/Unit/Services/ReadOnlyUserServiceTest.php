<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Services\ReadOnlyUserService;
use Mockery;
use Tests\TestCase;

class ReadOnlyUserServiceTest extends TestCase
{
    public function test_username_for_client_generates_deterministic_name(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getAttribute')->with('id')->andReturn('42');

        $service = new ReadOnlyUserService();
        $username = $service->usernameForClient($client);

        $this->assertMatchesRegularExpression('/^fms_[a-z0-9]{1,12}_[a-f0-9]{8}$/', $username);
        // Second call must return same value
        $this->assertSame($username, $service->usernameForClient($client));
    }

    public function test_username_for_client_sanitizes_special_chars(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getAttribute')->with('id')->andReturn(999);

        $service = new ReadOnlyUserService();
        $username = $service->usernameForClient($client);

        $this->assertMatchesRegularExpression('/^fms_[a-z0-9_]+_[a-f0-9]{8}$/', $username);
        $this->assertStringNotContainsString('@', $username);
    }

    public function test_provision_creates_user_and_grants_select(): void
    {
        $mockPdo = Mockery::mock(\PDO::class);
        $mockPdo->shouldReceive('exec')
            ->with(Mockery::on(fn(string $sql) => (bool) preg_match('/^DROP USER IF EXISTS \'fms_[a-z0-9_]+\'@\'%\'$/', $sql)))
            ->once()
            ->andReturn(0);
        $mockPdo->shouldReceive('exec')
            ->with(Mockery::on(fn(string $sql) => (bool) preg_match('/^CREATE USER \'fms_[a-z0-9_]+\'@\'%\' IDENTIFIED BY \'[a-f0-9]{32}\'$/', $sql)))
            ->once()
            ->andReturn(0);
        $mockPdo->shouldReceive('exec')
            ->with(Mockery::on(fn(string $sql) => (bool) preg_match('/^GRANT SELECT ON `test_db`\.\* TO \'fms_[a-z0-9_]+\'@\'%\'$/', $sql)))
            ->once()
            ->andReturn(0);
        $mockPdo->shouldReceive('exec')
            ->with("FLUSH PRIVILEGES")
            ->once()
            ->andReturn(0);

        $service = new ReadOnlyUserService();
        $password = $service->provision('fms_hotelparadise_aabb1122', 'test_db', $mockPdo);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $password);
    }

    public function test_provision_returns_32_char_hex_password(): void
    {
        $mockPdo = Mockery::mock(\PDO::class);
        $mockPdo->shouldReceive('exec')->andReturn(0);

        $service = new ReadOnlyUserService();
        $password = $service->provision('fms_testclient_aabb1122', 'test_db', $mockPdo);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $password);
    }

    public function test_deprovision_drops_user(): void
    {
        $mockPdo = Mockery::mock(\PDO::class);
        $mockPdo->shouldReceive('exec')
            ->with("DROP USER IF EXISTS 'fms_hotelacme_aabb1122'@'%'")
            ->once()
            ->andReturn(0);
        $mockPdo->shouldReceive('exec')
            ->with("FLUSH PRIVILEGES")
            ->once()
            ->andReturn(0);

        $service = new ReadOnlyUserService();
        $service->deprovision('fms_hotelacme_aabb1122', $mockPdo);
    }

    public function test_provision_throws_on_pdo_error(): void
    {
        $mockPdo = Mockery::mock(\PDO::class);
        $mockPdo->shouldReceive('exec')
            ->andThrow(new \PDOException('Access denied for user'));

        $this->expectException(\PDOException::class);

        $service = new ReadOnlyUserService();
        $service->provision('fms_testclient_aabb1122', 'test_db', $mockPdo);
    }
}
