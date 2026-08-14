<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SchemaMetadata;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = Client::withCount('users')->get();

        $totalClients = $clients->count();
        $activeClients = $clients->where('is_active', true)->count();

        $totalTables = SchemaMetadata::where('metadata_type', 'table')
            ->where('is_archived', false)
            ->count();

        $clientBreakdown = $clients->map(fn($c) => [
            'client_id' => $c->id,
            'name' => $c->name,
            'is_active' => $c->is_active,
            'users_count' => $c->users_count,
            'created_at' => $c->created_at?->toDateString(),
        ]);

        return response()->json([
            'total_clients' => $totalClients,
            'active_clients' => $activeClients,
            'total_tables' => $totalTables,
            'client_breakdown' => $clientBreakdown,
        ]);
    }
}
