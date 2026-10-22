<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * A basic Content-Security-Policy: scripts only from this site (Vite's tags and the page's one
 * inline script carry a per-request nonce) and Cloudflare Turnstile on the sign-up form;
 * everything else from this site. Styles allow inline ones (React style attributes, the page's
 * background <style>). Skipped while the Vite dev server runs (its scripts come from another
 * origin). The other security headers (HSTS, Referrer-Policy, ...) are set by nginx.
 */
class ContentSecurityPolicy
{
    private const TURNSTILE = 'https://challenges.cloudflare.com';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Vite::isRunningHot()) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' ".self::TURNSTILE,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self' ".self::TURNSTILE,
            "media-src 'self' blob:",
            'frame-src '.self::TURNSTILE,
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        return $response;
    }
}
