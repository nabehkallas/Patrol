<?php

namespace App\Http\Middleware;

use App\Support\ClientIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side guards on the two guest password-reset endpoints. Runs before the station is
 * resolved, so its counters and locks all live in the central database -- otherwise requests
 * for emails in different stations would each count in a separate station database:
 *
 * - "email me a link" (password.email): at most 3 requests a minute per IP. The broker itself
 *   adds a 60-second cooldown per account, and the link expires after a few minutes.
 * - "set my new password" (password.update): at most 10 attempts a minute per IP, and each
 *   attempt holds a lock on that email, so two requests carrying the same token can't both pass
 *   the check before the first one deletes it (the token is single-use).
 */
class ProtectPasswordReset
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') && $request->routeIs('password.email')) {
            if ($this->tooMany('password-reset-request:'.ClientIp::of($request), 3)) {
                return $this->throttled($request);
            }

            return $next($request);
        }

        if ($request->isMethod('post') && $request->routeIs('password.update')) {
            if ($this->tooMany('password-reset-submit:'.ClientIp::of($request), 10)) {
                return $this->throttled($request);
            }

            $email = Str::lower(trim((string) $request->input('email')));
            $lock = Cache::lock('password-reset:'.sha1($email), 15);

            return $lock->block(10, fn () => $next($request));
        }

        return $next($request);
    }

    private function tooMany(string $key, int $perMinute): bool
    {
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    private function throttled(Request $request): Response
    {
        $message = __('passwords.throttled');

        return $request->wantsJson()
            ? response()->json(['message' => $message], 429)
            : back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }
}
