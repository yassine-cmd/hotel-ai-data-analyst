<?php

namespace App\Http\Controllers;

use App\Models\PermissionToken;
use App\Services\UserAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPermissionController extends Controller
{
    public function __construct(
        private UserAccessService $userAccess,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PermissionToken::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:64|unique:permission_tokens,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'grants' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $token = PermissionToken::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'grants' => $validated['grants'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->userAccess->forgetTokenGrantsCache();

        return response()->json(['data' => $token], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $token = PermissionToken::find($id);
        if (!$token) {
            return response()->json(['error' => 'Permission token not found.'], 404);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('permission_tokens', 'code')->ignore($id)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'grants' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $token->fill([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'grants' => $validated['grants'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);
        $token->save();

        $this->userAccess->forgetTokenGrantsCache();

        return response()->json(['data' => $token]);
    }

    public function destroy(int $id): JsonResponse
    {
        $token = PermissionToken::find($id);
        if (!$token) {
            return response()->json(['error' => 'Permission token not found.'], 404);
        }

        $token->delete();
        $this->userAccess->forgetTokenGrantsCache();

        return response()->json(['status' => 'ok']);
    }
}