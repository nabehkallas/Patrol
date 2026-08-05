<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the day-to-day operational pages (cash box, transactions, sadcop, etc.) behind
 * completing the first-run wizard — but deliberately does NOT gate the admin/* setup pages
 * (tanks, fuel types, pumps, exchange rates) or settings, since the wizard's job is collecting
 * opening balances for whatever the admin has already configured there, not re-implementing
 * that entity-creation UI. Only applied to the routes that need real data to make sense.
 */
class RequireOnboarding
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized
            && tenant('onboarded_at') === null
            && ! $request->routeIs('onboarding.*')
            && ! $request->routeIs('logout')) {
            return redirect()->route('onboarding.wizard');
        }

        return $next($request);
    }
}
