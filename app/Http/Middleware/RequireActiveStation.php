<?php

namespace App\Http\Middleware;

use App\Enums\StationStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a self-registered station out of the app until a platform admin approves it: its owner
 * is sent to the "awaiting approval" screen instead. (Before the email is confirmed, the
 * 'verified' middleware has already sent them to the verify screen.)
 */
class RequireActiveStation
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && StationStatus::of($tenant) !== StationStatus::Active) {
            return redirect()->route('registration.pending');
        }

        return $next($request);
    }
}
