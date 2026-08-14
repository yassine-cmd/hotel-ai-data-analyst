<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->client_id) {
            abort(403, 'Only client users can access profile.');
        }
        $user->load('client');

        return response()->json([
            'user' => $user,
            'client' => $user->client,
        ]);
    }
}
