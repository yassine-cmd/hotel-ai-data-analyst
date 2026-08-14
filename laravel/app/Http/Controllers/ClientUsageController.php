<?php

namespace App\Http\Controllers;

use App\Services\ClientUsageService;
use App\Services\UserAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientUsageController extends Controller
{
    public function __construct(
        private ClientUsageService $usageService,
        private UserAccessService $accessService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->client_id) {
            abort(403, 'User is not associated with any client');
        }
        $client = $user->client;

        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
            'users_page' => 'nullable|integer|min:1',
            'users_per_page' => 'nullable|integer|min:1',
        ]);

        $data = $this->usageService->getClientDashboard($client, [
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? config('pagination.default')),
            'users_page' => (int) ($validated['users_page'] ?? 1),
            'users_per_page' => (int) ($validated['users_per_page'] ?? config('pagination.default')),
            'user_id' => $this->accessService->isAdmin($user) ? null : $user->id,
        ]);

        return response()->json([
            'totals' => $data['totals'],
            'budget' => $data['budget'],
            'sessions' => $data['sessions'],
            'sessions_meta' => $data['sessions_meta'],
            'per_user' => $data['per_user'],
            'per_user_meta' => $data['per_user_meta'],
        ]);
    }
}