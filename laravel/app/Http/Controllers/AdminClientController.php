<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PermissionToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ClientSignatureService;
use App\Services\ClientUsageService;
use App\Services\HotelUserSyncService;
use App\Services\ReadOnlyUserService;
use App\Services\TokenCostService;
use App\Support\ClientCredentialCipher;
use App\Support\Dsn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PDO;

class AdminClientController extends Controller
{
    public function __construct(
        private ReadOnlyUserService $readOnlyUser,
        private TokenCostService $costService,
        private HotelUserSyncService $userSync,
        private ClientUsageService $usageService,
        private ClientSignatureService $signature,
        private AuditLogger $audit
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Client::query();

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('analytics_db_dsn', 'like', "%{$search}%");
            });
        }

        $status = $request->query('status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'deactivated') {
            $query->where('is_active', false);
        }

        return response()->json(
            $query->withCount('users')->with('users:id,username,client_id')->get()
                ->map(fn ($c) => array_merge($c->toArray(), [
                    'month_spend_usd' => $this->costService->monthSpendForClient($c),
                ]))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string',
            'analytics_db_dsn' => 'required|string',
            'analytics_admin_user' => 'required|string',
            'analytics_admin_password' => 'nullable|string',
            'agent_style' => 'nullable|array',
            'budget_limit_usd' => 'nullable|numeric|min:0',
            'public_key' => 'nullable|string|size:64',
        ]);

        $client = Client::create([
            'name' => $request->name,
            'analytics_db_dsn' => $request->analytics_db_dsn,
            'analytics_agent_user' => '',
            'analytics_agent_password' => ClientCredentialCipher::encrypt(''),
            'analytics_admin_user' => $request->analytics_admin_user,
            'analytics_admin_password' => ClientCredentialCipher::encrypt($request->analytics_admin_password ?? ''),
            'agent_style' => $request->agent_style,
            'budget_limit_usd' => $request->budget_limit_usd !== null && $request->budget_limit_usd !== ''
                ? $request->budget_limit_usd
                : null,
            'public_key' => $request->public_key,
        ]);

        // The read-only DB user is provisioned automatically on the first
        // data-plane connection (ReadOnlyUserService::ensureClientAgentUserIfNeeded).
        $client->analytics_agent_user = $this->readOnlyUser->usernameForClient($client);
        $client->save();

        return response()->json(['client' => $client], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Client::with('users')->findOrFail($id));
    }

    public function dashboard(Request $request, int $id): JsonResponse
    {
        $client = Client::query()->findOrFail($id);

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max(1, (int) $request->integer('per_page', config('pagination.default'))), config('pagination.max'));

        $usage = $this->usageService->getClientDashboard($client, ['include_per_user' => false]);

        $usersQuery = $client->users()->orderBy('name');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        $role = $request->query('role');
        if ($role === '0' || $role === '1') {
            $usersQuery->whereJsonContains('permissions->role', (int) $role);
        }

        $status = $request->query('status');
        if ($status === 'active') {
            $usersQuery->whereNull('deactivated_at');
        } elseif ($status === 'deactivated') {
            $usersQuery->whereNotNull('deactivated_at');
        }

        $totalUsers = $usersQuery->count();
        $lastPage = max(1, (int) ceil($totalUsers / $perPage));
        $page = min($page, $lastPage);

        $users = $usersQuery->forPage($page, $perPage)->get();

        $tokenCodes = $users
            ->pluck('permissions')
            ->flatMap(fn ($perms) => (array) ($perms['permissions'] ?? []))
            ->unique()
            ->filter();
        $tokenIndex = PermissionToken::whereIn('code', $tokenCodes)
            ->get(['code', 'name', 'is_active'])
            ->mapWithKeys(fn ($t) => [$t->code => ['code' => $t->code, 'name' => $t->name, 'is_active' => $t->is_active]]);

        $usageById = $this->usageService->getPerUserUsage($client)->keyBy(fn ($row) => (int) $row['user_id']);

        $users = $users->sortBy('name')->values()->map(function (User $u) use ($tokenIndex, $usageById) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'department' => $u->department,
                'role' => (int) ($u->permissions['role'] ?? 0),
                'permissions' => array_values(array_map(
                    fn (string $code) => $tokenIndex->get($code, ['code' => $code, 'name' => $code, 'is_active' => true]),
                    (array) ($u->permissions['permissions'] ?? [])
                )),
                'external_id' => $u->external_id,
                'last_synced_at' => $u->last_synced_at?->toIso8601String(),
                'deactivated_at' => $u->deactivated_at?->toIso8601String(),
                'usage' => $usageById->get((int) $u->id),
            ];
        })->values();

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'analytics_db_dsn' => $client->analytics_db_dsn,
                'analytics_db_host' => $client->analytics_db_host,
                'analytics_db_port' => $client->analytics_db_port,
                'analytics_db_name' => $client->analytics_db_name,
                'is_active' => $client->is_active,
                'created_at' => $client->created_at?->toIso8601String(),
                'budget_limit_usd' => $client->budget_limit_usd,
                'public_key' => $client->public_key,
            ],
            'budget' => $usage['budget'],
            'totals' => $usage['totals'],
            'users' => $users,
            'users_meta' => [
                'total' => $totalUsers,
                'page' => (int) $page,
                'per_page' => (int) $perPage,
                'last_page' => (int) $lastPage,
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string',
            'analytics_db_dsn' => 'nullable|string',
            'analytics_admin_user' => 'nullable|string',
            'analytics_admin_password' => 'nullable|string',
            'agent_style' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'budget_limit_usd' => 'nullable|numeric|min:0',
            'public_key' => 'nullable|string|size:64',
        ]);

        if ($request->filled('name')) {
            $client->name = $request->name;
        }
        if ($request->filled('analytics_db_dsn')) {
            $client->analytics_db_dsn = $request->analytics_db_dsn;
        }
        if ($request->filled('analytics_admin_user')) {
            $client->analytics_admin_user = $request->analytics_admin_user;
        }
        if ($request->filled('analytics_admin_password')) {
            $client->analytics_admin_password = ClientCredentialCipher::encrypt($request->analytics_admin_password);
        }
        if ($request->has('agent_style') && is_array($request->agent_style)) {
            $client->agent_style = array_merge($client->agent_style ?? [], $request->agent_style);
        }
        if ($request->has('is_active')) {
            $client->is_active = $request->boolean('is_active');
        }
        if ($request->has('budget_limit_usd')) {
            $client->budget_limit_usd = $request->budget_limit_usd === null || $request->budget_limit_usd === ''
                ? null
                : $request->budget_limit_usd;
        }
        if ($request->has('public_key')) {
            $client->public_key = $request->public_key ?: null;
        }

        $client->save();

        return response()->json($client);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'dsn' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        $parts = Dsn::parse($request->dsn, ['host' => '127.0.0.1', 'port' => 3306, 'database' => '']);

        try {
            new PDO(
                "mysql:host={$parts['host']};port={$parts['port']};dbname={$parts['database']};charset=utf8mb4",
                $request->username,
                $request->password ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );

            Log::info('DB connection test succeeded', ['dsn' => $request->dsn]);

            return response()->json([
                'success' => true,
                'message' => 'Connection successful.',
            ]);
        } catch (\Exception $e) {
            Log::warning('DB connection test failed', ['dsn' => $request->dsn, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }

    public function deactivate(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->is_active = false;
        $client->deactivated_at = now();
        $client->save();

        $this->audit->info('admin.client.deactivated', [
            'client_id' => $client->id,
            'user_id' => request()->user()?->id,
        ]);

        return response()->json($client);
    }

    public function reactivate(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->is_active = true;
        $client->deactivated_at = null;
        $client->save();

        $this->audit->info('admin.client.reactivated', [
            'client_id' => $client->id,
            'user_id' => request()->user()?->id,
        ]);

        return response()->json($client);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        if (!Hash::check($validated['password'], $request->user()->password)) {
            return response()->json(['error' => 'Incorrect password. Deletion cancelled.'], 403);
        }

        $client = Client::findOrFail($id);

        if ($client->analytics_admin_user && $client->analytics_admin_password) {
            try {
                $this->readOnlyUser->deprovisionFromClient($client);
            } catch (\Exception $e) {
                Log::warning('Failed to deprovision read-only user | client=' . $client->id . ' | error=' . $e->getMessage());
            }
        }

        $client->users()->delete();
        $client->delete();

        return response()->json(['status' => 'ok']);
    }

    public function generateKeys(Request $request, int $id): JsonResponse
    {
        if (!extension_loaded('sodium')) {
            return response()->json(['error' => 'sodium extension not available'], 500);
        }

        $validated = $request->validate(['password' => 'required|string']);
        if (!Hash::check($validated['password'], $request->user()->password)) {
            return response()->json(['error' => 'Incorrect password.'], 403);
        }

        $client = Client::findOrFail($id);
        $keys = $this->signature->generate();

        $client->public_key = $keys['public_key'];
        $client->save();

        $this->audit->info('admin.client.key_generated', [
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'public_key'  => $keys['public_key'],
            'private_key' => $keys['private_key'],
        ]);
    }

    public function discoverUsers(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        try {
            $result = $this->userSync->discover($client);

            return response()->json([
                'data' => [
                    'client_id' => $client->id,
                    'status' => 'completed',
                    'summary' => $result['summary'],
                    'users' => array_map(fn ($u) => $this->presentUser($u), $result['rows']),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'USER_DISCOVERY_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    public function syncUsers(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        try {
            $result = $this->userSync->sync($client);

            return response()->json([
                'data' => [
                    'client_id' => $client->id,
                    'status' => 'completed',
                    'summary' => $result['summary'],
                    'users' => array_map(fn ($u) => $this->presentUser($u), $result['rows']),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'USER_SYNC_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    public function deactivateUser(int $clientId, int $userId): JsonResponse
    {
        $user = $this->findClientUser($clientId, $userId);
        $user->deactivated_at = now();
        $user->save();

        return response()->json(['id' => $user->id, 'deactivated_at' => $user->deactivated_at->toIso8601String()]);
    }

    public function activateUser(int $clientId, int $userId): JsonResponse
    {
        $user = $this->findClientUser($clientId, $userId);
        $user->deactivated_at = null;
        $user->save();

        return response()->json(['id' => $user->id, 'deactivated_at' => null]);
    }

    private function findClientUser(int $clientId, int $userId): User
    {
        Client::findOrFail($clientId);

        return User::where('client_id', $clientId)->whereKey($userId)->firstOrFail();
    }

    private function presentUser(array $user): array
    {
        return [
            'external_id' => $user['external_id'],
            'username' => $user['username'],
            'name' => $user['name'],
            'department' => $user['department'],
            'permissions' => $user['permissions'],
            'status' => $user['status'],
            'is_new' => $user['is_new'] ?? false,
            'is_changed' => $user['is_changed'] ?? false,
            'user_id' => $user['user_id'] ?? null,
        ];
    }
}
