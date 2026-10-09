<?php

namespace App\Http\Middleware;

use App\Enums\StationStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a station's users out of the app unless the station is live:
 *  - not yet approved (self-registered): the owner sees the "awaiting approval" screen;
 *  - suspended by a platform admin: anyone still signed in is signed out and sent to the
 *    login page with the "subscription expired" message (login itself refuses them too,
 *    see FortifyServiceProvider::authenticateUsing).
 * (Before the email is confirmed, the 'verified' middleware has already sent them to the
 * verify screen.)
 */
class RequireActiveStation
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        // An employee disabled while signed in is signed out at their next click.
        if ($request->user()?->disabled_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('This account is disabled. Ask your station admin to enable it.')]);
        }

        return match (StationStatus::of($tenant)) {
            StationStatus::Active => $next($request),
            StationStatus::Suspended => self::signOutSuspended($request),
            default => redirect()->route('registration.pending'),
        };
    }

    public static function signOutSuspended(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => __('Subscription expired. Please contact support.')]);
    }
}
