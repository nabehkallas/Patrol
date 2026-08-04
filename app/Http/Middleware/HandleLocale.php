<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie('locale') === 'ar' ? 'ar' : 'en';

        app()->setLocale($locale);

        View::share('locale', $locale);
        View::share('direction', $locale === 'ar' ? 'rtl' : 'ltr');

        return $next($request);
    }
}
