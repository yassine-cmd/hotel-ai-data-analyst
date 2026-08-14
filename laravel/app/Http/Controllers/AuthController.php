<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InstanceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required',
        ]);

        $identity = app(InstanceIdentity::class);

        if ($identity->isAdmin()) {
            try {
                return $this->loginAdmin($request);
            } catch (ValidationException $e) {
                // Dual-role instance: if no Admin user matched, fall back to
                // client login so the same instance can also serve its tenant.
                if ($identity->clientId() !== null) {
                    return $this->loginClient($request, $identity);
                }
                throw $e;
            }
        }

        return $this->loginClient($request, $identity);
    }

    private function loginAdmin(Request $request): JsonResponse
    {
        $admin = Admin::where('username', $request->username)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            $this->audit->warning('auth.login.failed', [
                'username' => $request->username,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        Auth::guard('admin')->login($admin);

        $this->audit->info('auth.login.success', [
            'user_id' => $admin->id,
            'username' => $admin->username,
            'ip' => $request->ip(),
            'client_id' => null,
        ]);

        return response()->json([
            'user' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'username' => $admin->username,
                'is_admin' => true,
                'client_id' => null,
                'role' => 0,
            ],
        ]);
    }

    private function loginClient(Request $request, InstanceIdentity $identity): JsonResponse
    {
        $user = User::with('client')
            ->where('username', $request->username)
            ->where('client_id', $identity->clientId())
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->audit->warning('auth.login.failed', [
                'username' => $request->username,
                'ip' => $request->ip(),
                'client_id' => $identity->clientId(),
            ]);

            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ((!$user->client || !$user->client->is_active) || $user->deactivated_at !== null) {
            $this->audit->warning('auth.login.deactivated', [
                'username' => $user->username,
                'ip' => $request->ip(),
                'client_id' => $user->client_id,
            ]);

            abort(403, 'Your account has been deactivated. Contact an administrator.');
        }

        Auth::guard('web')->login($user);

        $this->audit->info('auth.login.success', [
            'user_id' => $user->id,
            'username' => $user->username,
            'ip' => $request->ip(),
            'client_id' => $user->client_id,
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'is_admin' => false,
                'client_id' => $user->client?->id,
                'role' => (int) ($user->permissions['role'] ?? 0),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->info('auth.logout', [
            'user_id' => $request->user()?->id,
            'username' => $request->user()?->username,
            'client_id' => $request->user() instanceof User ? $request->user()->client_id : null,
        ]);

        Auth::guard('admin')->logout();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function user(Request $request): JsonResponse
    {
        $principal = $request->user();
        $isAdmin = $principal instanceof Admin;

        if (!$isAdmin) {
            $principal->load('client');
        }

        return response()->json([
            'id' => $principal->id,
            'name' => $principal->name,
            'username' => $principal->username,
            'is_admin' => $isAdmin,
            'client_id' => $isAdmin ? null : $principal->client?->id,
            'role' => $isAdmin ? 0 : (int) ($principal->permissions['role'] ?? 0),
        ]);
    }
}
