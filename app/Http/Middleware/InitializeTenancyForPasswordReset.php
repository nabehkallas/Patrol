<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guest password-reset requests ("email me a link" and "set my new password") arrive with
 * no station in the session, so on their own they only see the central database, where station
 * users don't live. This switches to the station the email belongs to, using the same central
 * directory login uses, so the password broker finds the user and stores/checks the reset token
 * in that station's own database. Platform admins (central users) are left on the central
 * connection. Limited to those two routes: login does its own station lookup.
 */
class InitializeTenancyForPasswordReset
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized || ! $request->routeIs('password.email', 'password.update')) {
            return $next($request);
        }

        $email = $request->string('email')->trim()->toString();

        if ($email === '' || User::where('email', $email)->exists()) {
            return $next($request);
        }

        $entry = TenantUserDirectory::where('email', $email)->first();
        $tenant = $entry ? Tenant::find($entry->tenant_id) : null;

        if ($tenant) {
            tenancy()->initialize($tenant);
        }

        return $next($request);
    }
}
