<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * List and report pages read `from` / `to` date filters straight from the query string. A
 * malformed value (anything but a real YYYY-MM-DD date) is dropped here, so the page falls back
 * to its default range instead of failing with a server error.
 */
class SanitizeDateFilters
{
    private const KEYS = ['from', 'to', 'date'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::KEYS as $key) {
            if ($request->query->has($key) && ! self::isDate($request->query->get($key))) {
                $request->query->remove($key);
            }
        }

        return $next($request);
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
