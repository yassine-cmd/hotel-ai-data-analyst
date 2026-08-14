<?php

namespace App\Http\Controllers;

use App\Services\PythonProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AnalyzeController extends Controller
{
    public function __construct(
        private PythonProxyService $pythonProxy
    ) {}

    public function analyze(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
            'session_id' => 'nullable|string',
        ]);

        $user = $request->user();
        if (!$user->client_id) {
            // Intentionally no admin ?client_id= override here — analyze
            // triggers live queries against the client's production analytics
            // database and costs LLM tokens. An admin bypass would produce
            // actions invisible inside client audit trails.
            // See ClientSchemaController for the read-only exception pattern.
            abort(403, 'User is not associated with any client');
        }

        return $this->pythonProxy->analyze(
            $request->input('query'),
            $request->input('session_id', Str::uuid()->toString()),
            $user->client,
            $user->id,
            $user->name
        );
    }
}
