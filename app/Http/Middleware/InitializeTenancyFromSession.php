<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-establishes tenant context on every request for a logged-in station user. Login itself
 * (see FortifyServiceProvider::authenticateUsing) only initializes tenancy for the duration of
 * that one request and stores which tenant in the session — every later request needs this to
 * switch back to the right database before anything else runs. Platform admins never have a
 * `tenant_id` in session, so they stay on the central connection throughout.
 */
class InitializeTenancyFromSession
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            return $next($request);
        }

        $tenantId = session('tenant_id');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if ($tenant) {
                tenancy()->initialize($tenant);
            } else {
                session()->forget('tenant_id');
                Auth::logout();
            }
        }

        return $next($request);
    }
}
