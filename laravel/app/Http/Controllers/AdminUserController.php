<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Admin::orderBy('id')->get()->map(fn ($a) => $this->mapAdmin($a)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:6',
        ]);

        $admin = Admin::create([
            'name' => $request->name,
            'username' => $request->username,
            'password' => $request->password,
        ]);

        return response()->json($this->mapAdmin($admin), 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->mapAdmin(Admin::findOrFail($id)));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
        ]);

        $admin = Admin::findOrFail($id);
        if ($request->filled('name')) {
            $admin->name = $request->name;
        }
        if ($request->filled('username')) {
            $admin->username = $request->username;
        }
        if ($request->filled('password')) {
            $admin->password = $request->password;
        }
        $admin->save();

        return response()->json($this->mapAdmin($admin));
    }

    public function destroy(int $id): JsonResponse
    {
        Admin::findOrFail($id)->delete();

        return response()->json(['status' => 'ok']);
    }

    private function mapAdmin(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'username' => $admin->username,
            'is_admin' => true,
            'client_id' => null,
            'client_name' => null,
            'created_at' => $admin->created_at,
        ];
    }
}
