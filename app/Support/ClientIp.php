<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The visitor's real IP, for rate limiting. Behind Fly's proxy every proxy is trusted, so
 * Request::ip() reads X-Forwarded-For -- whose left part the client can forge to dodge a
 * per-IP limit. Fly sets Fly-Client-IP itself at the edge and overwrites any client value,
 * so prefer it; locally (no Fly header) fall back to the normal IP.
 */
final class ClientIp
{
    public static function of(Request $request): string
    {
        $flyClientIp = $request->header('Fly-Client-IP');

        if (is_string($flyClientIp) && filter_var($flyClientIp, FILTER_VALIDATE_IP)) {
            return $flyClientIp;
        }

        return (string) $request->ip();
    }
}
