<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A station admin created via StationController::store gets a temporary password and
 * `must_change_password = true` — this locks every tenant route behind setting a real password
 * first, except the change-password screen itself (and logout, so they're not stuck).
 */
class ForcePasswordChange
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password
            && ! $request->routeIs('password.force-change*')
            && ! $request->routeIs('logout')) {
            return redirect()->route('password.force-change');
        }

        return $next($request);
    }
}
