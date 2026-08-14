<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\User;
use App\Services\HotelUserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotelUserSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private HotelUserSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createClient();
        $this->service = $this->app->make(HotelUserSyncService::class);
    }

    private static function row(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 1,
            'username' => 'jdupont',
            'name' => 'Jean Dupont',
            'password' => bcrypt('password'),
            'permissions' => ['role' => 0, 'permissions' => ['RESERVATION', 'RECEPTION']],
            'department' => 'Reception',
        ], $overrides);
    }

    public function test_apply_rows_creates_new_users(): void
    {
        [$applied, $counts] = $this->service->applyRows($this->client, [self::row()]);

        $this->assertSame(1, $counts['created']);
        $this->assertSame('created', $applied[0]['status']);

        $this->assertDatabaseHas('users', [
            'client_id' => $this->client->id,
            'username' => 'jdupont',
            'external_id' => 1,
            'name' => 'Jean Dupont',
            'department' => 'Reception',
            'password_hash_source' => 'hotel',
        ]);
    }

    public function test_apply_rows_copies_bcrypt_hash_verbatim(): void
    {
        $hash = bcrypt('hotel-secret');
        $this->service->applyRows($this->client, [self::row(['password' => $hash])]);

        $this->assertSame($hash, User::where('external_id', 1)->first()->password);
    }

    public function test_apply_rows_is_idempotent_after_initial_sync(): void
    {
        $row = self::row();
        $this->service->applyRows($this->client, [$row]);
        [$applied, $counts] = $this->service->applyRows($this->client, [$row]);

        $this->assertSame(1, $counts['seen']);
        $this->assertSame(0, $counts['created']);
        $this->assertSame(1, $counts['synced']);
        $this->assertSame('synced', $applied[0]['status']);
    }

    public function test_apply_rows_updates_changed_existing_user(): void
    {
        $this->service->applyRows($this->client, [self::row()]);
        [$applied, $counts] = $this->service->applyRows($this->client, [self::row(['name' => 'Jean P. Dupont'])]);

        $this->assertSame(1, $counts['updated']);
        $this->assertSame('updated', $applied[0]['status']);
        $this->assertSame('Jean P. Dupont', User::where('external_id', 1)->first()->name);
    }

    public function test_apply_rows_adopts_manual_user_with_same_username(): void
    {
        User::create([
            'name' => 'Manual',
            'username' => 'jdupont',
            'password' => bcrypt('secret'),
            'client_id' => $this->client->id,
        ]);

        [$applied, $counts] = $this->service->applyRows($this->client, [self::row()]);

        $this->assertSame(1, $counts['adopted']);
        $this->assertSame('adopted', $applied[0]['status']);
        $this->assertSame(1, User::where('client_id', $this->client->id)->count());
        $this->assertSame(1, User::where('client_id', $this->client->id)->first()->external_id);
    }

    public function test_apply_rows_adopts_username_collision_overwriting_local_user(): void
    {
        $this->service->applyRows($this->client, [self::row(['external_id' => 9, 'username' => 'jdupont'])]);

        [$applied, $counts] = $this->service->applyRows($this->client, [self::row(['external_id' => 1])]);

        $this->assertSame(1, $counts['adopted']);
        $this->assertSame('adopted', $applied[0]['status']);
        $this->assertSame(1, User::where('client_id', $this->client->id)->first()->external_id);
        $this->assertSame(1, User::where('client_id', $this->client->id)->count());
    }

    public function test_sync_removes_local_users_absent_from_hotel(): void
    {
        // Two users that exist locally but are no longer in the hotel DB.
        User::create(array_merge(self::row(['external_id' => 10, 'username' => 'gone1']), ['client_id' => $this->client->id]));
        User::create(array_merge(self::row(['external_id' => 11, 'username' => 'gone2']), ['client_id' => $this->client->id]));
        // A manually-created local account (no external_id) must also be wiped.
        User::create([
            'name' => 'Manual',
            'username' => 'manual1',
            'password' => bcrypt('secret'),
            'client_id' => $this->client->id,
        ]);

        [$applied, $counts] = $this->service->applyRows($this->client, [self::row(['external_id' => 1, 'username' => 'jdupont'])]);

        $this->assertSame(3, $counts['removed']);
        $this->assertSame(1, User::where('client_id', $this->client->id)->count());
        $this->assertSame(1, User::where('client_id', $this->client->id)->first()->external_id);
        $this->assertDatabaseMissing('users', ['username' => 'gone1']);
        $this->assertDatabaseMissing('users', ['username' => 'manual1']);
    }

    public function test_sync_does_not_touch_other_clients_users(): void
    {
        $other = $this->createClient(['name' => 'Other Hotel']);
        User::create(array_merge(self::row(['external_id' => 99, 'username' => 'otheronly']), ['client_id' => $other->id]));

        $this->service->applyRows($this->client, [self::row(['external_id' => 1, 'username' => 'jdupont'])]);

        $this->assertSame(1, User::where('client_id', $other->id)->count());
        $this->assertSame(99, User::where('client_id', $other->id)->first()->external_id);
    }

    public function test_apply_rows_only_sees_own_client_users(): void
    {
        $other = $this->createClient(['name' => 'Other Hotel']);

        // Same username on a different client must not be adopted/resolved.
        User::create([
            'name' => 'Other Man',
            'username' => 'jdupont',
            'password' => bcrypt('secret'),
            'client_id' => $other->id,
        ]);

        [$applied, $counts] = $this->service->applyRows($this->client, [self::row()]);

        $this->assertSame(1, $counts['created']);
        $this->assertSame('created', $applied[0]['status']);
    }

    public function test_discover_normalises_stdclass_rows_from_live_connection(): void
    {
        $hash = bcrypt('hotel-secret');

        $conn = \Mockery::mock('connection');
        $conn->shouldReceive('select')->andReturn([
            (object) [
                'id_utilisateur' => '1',
                'login' => 'jdupont',
                'password' => $hash,
                'role' => '0',
                'permission' => 'RESERVATION,RECEPTION',
                'nom' => 'Dupont',
                'prenom' => 'Jean',
                'libelle' => 'Reception',
            ],
        ]);

        DB::shouldReceive('connection')->with('hotel_user_sync')->andReturn($conn);
        DB::shouldReceive('purge')->once();

        $result = $this->service->discover($this->client);

        $this->assertSame(1, $result['summary']['users_live']);
        $this->assertSame(1, $result['summary']['new']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        $this->assertSame(1, $row['external_id']);
        $this->assertSame('jdupont', $row['username']);
        $this->assertSame('Jean Dupont', $row['name']);
        $this->assertSame($hash, $row['password']);
        $this->assertSame('Reception', $row['department']);
        $this->assertSame(['role' => 0, 'permissions' => ['RESERVATION', 'RECEPTION']], $row['permissions']);
    }
}