<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientAdminMiddleware
{
    public function __construct(
        private UserAccessService $accessService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user
            || !$user instanceof User
            || !$user->client_id
            || !$this->accessService->isAdmin($user)) {
            abort(403, 'Client admin access required.');
        }

        return $next($request);
    }
}