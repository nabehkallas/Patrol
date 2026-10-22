<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF and Excel exports cover at most MAX_YEARS: a longer from/to range gets the error page
 * (exports open in a new tab) instead of a file. A missing `to` means today, as in the reports.
 * Sadcop's "all dates" view has no from/to and is left alone (its ledger is small).
 */
class LimitExportRange
{
    public const MAX_YEARS = 2;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $from = $request->date('from');
        $to = $request->date('to') ?? now();

        if ($from !== null && $from->copy()->startOfDay()->addYears(self::MAX_YEARS)->lt($to->copy()->startOfDay())) {
            return Inertia::render('error', ['status' => 422, 'reason' => 'export_range'])
                ->toResponse($request)
                ->setStatusCode(422);
        }

        return $next($request);
    }
}
