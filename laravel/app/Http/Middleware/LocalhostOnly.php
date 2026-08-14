<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalhostOnly
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->ip() !== '127.0.0.1' && $request->ip() !== '::1') {
            $this->audit->critical('security.loopback_violation', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'error' => ['code' => 'ACCESS_DENIED', 'message' => 'Endpoint is loopback-only', 'retryable' => false],
            ], 403);
        }

        return $next($request);
    }
}
