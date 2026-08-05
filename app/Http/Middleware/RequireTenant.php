<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every station-scoped route (tanks, transactions, cash box, etc.) assumes a tenant is active —
 * many controllers call `$user->isAdmin()` directly, which queries tables that only exist in a
 * tenant database. A platform admin (authenticated, no tenant) hitting one of these routes would
 * crash rather than fail cleanly, so they're redirected to their own area instead.
 */
class RequireTenant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! tenancy()->initialized) {
            return redirect()->route('platform.home');
        }

        return $next($request);
    }
}
