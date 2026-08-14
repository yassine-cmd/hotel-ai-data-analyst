<?php

namespace App\Http\Controllers;

use App\Models\PermissionToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client-admin management of role-0 staff logins for their own client.
 * Scope is hard-bound to the caller's client_id (middleware + per-request).
 */
class ClientStaffAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(max(1, (int) $request->integer('per_page', config('pagination.default'))), config('pagination.max'));

        $query = User::query()
            ->where('client_id', $me->client_id)
            ->where('id', '!=', $me->id)
            ->whereJsonContains('permissions->role', 0);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        $status = $request->query('status');
        if ($status === 'active') {
            $query->whereNull('deactivated_at');
        } elseif ($status === 'deactivated') {
            $query->whereNotNull('deactivated_at');
        }

        $query->orderBy('name');
        $total = $query->count();

        $users = $query->forPage($page, $perPage)->get();

        $tokenCodes = $users
            ->pluck('permissions')
            ->flatMap(fn ($perms) => (array) ($perms['permissions'] ?? []))
            ->unique()
            ->filter();
        $tokenIndex = PermissionToken::whereIn('code', $tokenCodes)
            ->get(['code', 'name', 'is_active'])
            ->mapWithKeys(fn ($t) => [$t->code => ['code' => $t->code, 'name' => $t->name, 'is_active' => $t->is_active]]);

        $data = $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'department' => $u->department,
            'permissions' => array_values(array_map(
                fn (string $code) => $tokenIndex->get($code, ['code' => $code, 'name' => $code, 'is_active' => true]),
                (array) ($u->permissions['permissions'] ?? [])
            )),
            'deactivated_at' => $u->deactivated_at?->toIso8601String(),
        ]);

        return response()->json([
            'users' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function deactivate(Request $request, int $userId): JsonResponse
    {
        $this->assertManageable($request->user(), $userId)->update(['deactivated_at' => now()]);
        return response()->json(['status' => 'ok']);
    }

    public function activate(Request $request, int $userId): JsonResponse
    {
        $this->assertManageable($request->user(), $userId)->update(['deactivated_at' => null]);
        return response()->json(['status' => 'ok']);
    }

    private function assertManageable(User $me, int $userId): User
    {
        $target = User::where('client_id', $me->client_id)
            ->where('id', $userId)
            ->where('id', '!=', $me->id)
            ->firstOrFail();
        if (($target->permissions['role'] ?? 0) !== 0) {
            abort(403, 'You can only manage staff users.');
        }
        return $target;
    }
}