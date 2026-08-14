<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HotelUserSyncService
{
    private const DISCOVERY_LOCK_KEY = 'hotel_user_sync_lock';
    private const DISCOVERY_LOCK_TTL = 120;

    /**
     * Discover the users in a client's hotel database using admin credentials.
     *
     * Reads from utilisateur/employe/departement (3-way join), normalises each
     * row and classifies it against the local registry WITHOUT writing anything.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function discover(Client $client): array
    {
        $lock = Cache::lock(self::DISCOVERY_LOCK_KEY, self::DISCOVERY_LOCK_TTL);

        if (!$lock->get()) {
            throw new \RuntimeException('A user sync run is already in progress.');
        }

        try {
            $live = $this->fetchLiveUsers($client) ?? throw new \RuntimeException(
                'Could not connect to the hotel database for user sync.'
            );

            $existing = $client->users()->get()->keyBy('id');

            $rows = [];
            $summary = [
                'users_live' => count($live),
                'users_local' => $existing->count(),
                'new' => 0,
                'changed' => 0,
                'synced' => 0,
                'conflicts' => 0,
            ];

            foreach ($live as $raw) {
                $row = $this->normaliseRow((array) $raw);
                $classification = $this->classify($client, $row, $existing);

                $rows[] = $row + $classification;
                $summary[$classification['status'] === 'synced'
                    ? 'synced'
                    : ($classification['status'] === 'conflict' ? 'conflicts' : $classification['status'])]++;
            }

            return ['rows' => $rows, 'summary' => $summary];
        } catch (\Throwable $e) {
            Log::error('Hotel user discovery failed', [
                'client' => $client->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Apply discovered hotel users to the local registry.
     *
     * @param  array<int, array<string, mixed>>|null  $rows  Normalised rows (fetched if null).
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>}
     */
    public function sync(Client $client, ?array $rows = null): array
    {
        if ($rows === null) {
            $rows = $this->discover($client)['rows'];
        }

        [$applied, $counts] = DB::transaction(fn () => $this->applyRows($client, $rows));

        return ['summary' => $counts, 'rows' => $applied];
    }

    /**
     * Persist a set of normalised hotel-user rows against a client.
     *
     * Public so it can be exercised in unit tests against an in-memory database.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{array<int, array<string, mixed>>, array<string, int>}
     */
    public function applyRows(Client $client, array $rows): array
    {
        $counts = [
            'seen' => 0,
            'created' => 0,
            'updated' => 0,
            'adopted' => 0,
            'synced' => 0,
            'removed' => 0,
        ];

        $applied = [];

        $liveExternalIds = array_column($rows, 'external_id');

        foreach ($rows as $row) {
            $counts['seen']++;

            [$candidate, $mode] = $this->resolve($client, $row);
            $userId = $candidate?->id;

            if ($mode === 'create') {
                User::create($this->attributesFor($client, $row, 'hotel'));
                $counts['created']++;
                $status = 'created';
            } else {
                $changed = $this->userDiffers($candidate, $row);

                $candidate->fill($this->attributesFor($client, $row, 'hotel'));
                $candidate->save();

                if ($mode === 'adopt') {
                    $counts['adopted']++;
                    $status = 'adopted';
                } elseif ($changed) {
                    $counts['updated']++;
                    $status = 'updated';
                } else {
                    $counts['synced']++;
                    $status = 'synced';
                }
            }

            $applied[] = $row + ['status' => $status, 'user_id' => $userId];
        }

        // Full sync: any local user that is no longer present in the hotel
        // database (including manually-created accounts with no external_id)
        // is removed so the registry reflects exactly what the hotel holds.
        $staleIds = User::where('client_id', $client->id)
            ->where(function ($query) use ($liveExternalIds) {
                $query->whereNull('external_id')
                    ->orWhereNotIn('external_id', $liveExternalIds);
            })
            ->pluck('id')
            ->all();

        $counts['removed'] = count($staleIds);
        User::whereIn('id', $staleIds)->delete();

        return [$applied, $counts];
    }

    /**
     * Resolve a live row to an existing local user (or creation intent).
     *
     * @return array{0: User|null, 1: 'create'|'update'|'adopt'|'conflict'}
     */
    private function resolve(Client $client, array $row): array
    {
        // 1. Existing synced user by stable external key.
        $byExternal = User::where('client_id', $client->id)
            ->where('external_id', $row['external_id'])
            ->first();

        if ($byExternal) {
            return [$byExternal, 'update'];
        }

        // 2. A local user sharing the same username (a manually-created account
        //    or a synced row from a reused login): adopt it so the live hotel
        //    record overwrites the local one during a full sync.
        $byUsername = User::where('client_id', $client->id)
            ->where('username', $row['username'])
            ->first();

        if ($byUsername) {
            return [$byUsername, 'adopt'];
        }

        return [null, 'create'];
    }

    /**
     * Classify a row without writing.
     *
     * @return array{is_new: bool, is_changed: bool, status: string}
     */
    private function classify(Client $client, array $row, \Illuminate\Support\Collection $existing): array
    {
        [$candidate, $mode] = $this->resolve($client, $row);

        return match ($mode) {
            'create' => ['is_new' => true, 'is_changed' => false, 'status' => 'new'],
            'conflict' => ['is_new' => false, 'is_changed' => false, 'status' => 'conflict'],
            default => $this->classifyExisting($candidate, $row),
        };
    }

    private function classifyExisting(User $user, array $row): array
    {
        $changed = $this->userDiffers($user, $row);

        return [
            'is_new' => false,
            'is_changed' => $changed,
            'status' => $changed ? 'changed' : 'synced',
        ];
    }

    private function userDiffers(User $user, array $row): bool
    {
        return $user->username !== $row['username']
            || $user->name !== $row['name']
            || $user->password !== $row['password']
            || ($user->department ?? null) !== $row['department']
            || $this->permissionsChanged($user->permissions, $row['permissions']);
    }

    private function permissionsChanged(mixed $current, array $incoming): bool
    {
        $live = ['role' => (int) $incoming['role'], 'permissions' => $incoming['permissions']];
        return json_encode($current ?? []) !== json_encode($live);
    }

    private function attributesFor(Client $client, array $row, string $source): array
    {
        return [
            'client_id' => $client->id,
            'external_id' => $row['external_id'],
            'username' => $row['username'],
            'name' => $row['name'],
            'password' => $row['password'],
            'permissions' => $row['permissions'],
            'department' => $row['department'],
            'password_hash_source' => $source,
            'last_synced_at' => now(),
        ];
    }

    /**
     * @return array{external_id: int, username: string, name: string, password: string, permissions: array{role: int, permissions: array<int, string>}, department: ?string}
     */
    private function normaliseRow(array $raw): array
    {
        $fullName = trim(trim($raw['prenom'] ?? '').' '.trim($raw['nom'] ?? ''));

        return [
            'external_id' => (int) $raw['id_utilisateur'],
            'username' => $raw['login'],
            'name' => $fullName !== '' ? $fullName : $raw['login'],
            'password' => $raw['password'],
            'permissions' => [
                'role' => (int) $raw['role'],
                'permissions' => $this->parsePermissionTokens($raw['permission']),
            ],
            'department' => $raw['libelle'] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parsePermissionTokens(?string $permission): array
    {
        if ($permission === null || trim($permission) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $permission))));
    }

    /**
     * Fetch user rows from the hotel database using admin credentials.
     */
    private function fetchLiveUsers(Client $client): ?array
    {
        $connName = 'hotel_user_sync';

        try {
            config([
                "database.connections.$connName" => [
                    'driver' => 'mysql',
                    'host' => $client->analytics_db_host,
                    'port' => $client->analytics_db_port,
                    'database' => $client->analytics_db_name,
                    'username' => $client->analytics_admin_user,
                    'password' => $client->decrypted_admin_password,
                    'charset' => 'utf8mb4',
                    'prefix' => '',
                    'strict' => true,
                ],
            ]);

            DB::connection($connName)->select('SELECT 1');

            return DB::connection($connName)->select("
                SELECT
                    u.id_utilisateur AS id_utilisateur,
                    u.login          AS login,
                    u.password       AS password,
                    u.role           AS role,
                    u.permission     AS permission,
                    e.nom            AS nom,
                    e.prenom         AS prenom,
                    d.libelle        AS libelle
                FROM utilisateur u
                LEFT JOIN employe e      ON e.id_employe = u.id_employe
                LEFT JOIN departement d  ON d.id_departement = e.id_departement
                ORDER BY u.id_utilisateur
            ");
        } catch (\Exception $e) {
            Log::error('Hotel user sync connection failed', [
                'client' => $client->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            DB::purge($connName);
        }
    }
}