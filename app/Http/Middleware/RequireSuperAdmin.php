<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror of RequireTenant: platform routes (station management) are for super admins only — a
 * station user who wanders onto one gets sent back to their own home instead.
 */
class RequireSuperAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            return redirect()->route('cash-box.index');
        }

        return $next($request);
    }
}
