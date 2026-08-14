<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SessionMetadata;
use App\Services\PythonProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __construct(
        private PythonProxyService $pythonProxy
    ) {}

    private function resolveClient(Request $request): Client
    {
        $user = $request->user();
        if ($user->client_id) {
            return $user->client;
        }
        abort(403, 'User is not associated with any client');
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        $sessions = SessionMetadata::where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        return response()->json(['sessions' => $sessions]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        $validated = $request->validate([
            'session_id' => 'nullable|string',
            'name' => 'nullable|string',
        ]);

        $sessionId = $validated['session_id'] ?? Str::uuid()->toString();

        $session = SessionMetadata::create([
            'session_id' => $sessionId,
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'name' => $validated['name'] ?? '',
            'turn_count' => 0,
            'context_window' => null,
            'path' => "{$client->id}/{$sessionId}/",
            'created_at' => now(),
            'last_access' => now(),
        ]);

        Log::info('Session created', [
            'client_id' => $client->id,
            'session_id' => $sessionId,
            'name' => $session->name,
        ]);

        return response()->json([
            'status' => 'ok',
            'session_id' => $sessionId,
            'name' => $session->name,
            'created_at' => $session->created_at,
        ]);
    }

    public function update(Request $request, string $session): JsonResponse
    {
        $client = $this->resolveClient($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $updated = SessionMetadata::where('session_id', $session)
            ->where('client_id', $client->id)
            ->update(['name' => $validated['name']]);

        if (!$updated) {
            Log::warning('Session not found for rename', [
                'client_id' => $client->id,
                'session_id' => $session,
            ]);
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        Log::info('Session renamed', [
            'client_id' => $client->id,
            'session_id' => $session,
            'name' => $validated['name'],
        ]);

        return response()->json([
            'status' => 'ok',
            'session_id' => $session,
            'name' => $validated['name'],
        ]);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $client = $this->resolveClient($request);

        SessionMetadata::where('session_id', $session)
            ->where('client_id', $client->id)
            ->delete();

        Log::info('Session deleted', [
            'client_id' => $client->id,
            'session_id' => $session,
        ]);

        $this->pythonProxy->forwardPost(
            "/internal/sessions/{$client->id}/{$session}/cleanup"
        );

        return response()->json([
            'status' => 'ok',
            'client_id' => $client->id,
            'session_id' => $session,
        ]);
    }

    public function history(Request $request, string $session): JsonResponse
    {
        $client = $this->resolveClient($request);

        return response()->json(
            $this->pythonProxy->forwardGet("/internal/sessions/{$client->id}/{$session}/history")
        );
    }

    public function download(Request $request, string $session, string $name)
    {
        $client = $this->resolveClient($request);
        $response = $this->pythonProxy->forwardGetRaw(
            "/internal/sessions/{$client->id}/{$session}/artifacts/{$name}/download"
        );

        return response(
            $response->body(),
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => $response->header('Content-Disposition'),
            ]
        );
    }
}
