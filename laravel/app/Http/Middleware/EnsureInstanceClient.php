<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\User;
use App\Services\InstanceIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts client-facing routes to the tenant this instance actually serves,
 * derived from its keypair. Admins never reach client routes, and a client
 * user can only act for their own tenant.
 *
 * On a dual-role instance (both ADMIN_PRIVATE_KEY and CLIENT_PRIVATE_KEY set)
 * the client side still resolves to its served tenant, so that tenant's client
 * users are permitted on client routes; only the instance's *own* tenant may
 * pass. If the instance serves no client tenant (pure admin, or a dual-role
 * instance whose client key matched no registered client) every client user is
 * blocked.
 */
class EnsureInstanceClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = app(InstanceIdentity::class);

        $user = $request->user();

        if ($user instanceof Admin) {
            abort(403);
        }

        if (!$user instanceof User) {
            return $next($request);
        }

        $instanceClientId = $identity->clientId();
        if ($instanceClientId === null) {
            // No client tenant is served by this instance: a pure admin instance,
            // or a dual-role instance whose client key matched no registered
            // client. Client users never reach client routes here.
            abort(403);
        }

        if ($user->client_id !== $instanceClientId) {
            abort(403);
        }

        return $next($request);
    }
}
