<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleLocale
{
    /**
     * Applies the interface language chosen in the browser (the `locale` cookie) to server
     * messages, emails and exports, and gives the page its text direction.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie('locale');
        $locale = is_string($cookie) && Locales::isSupported($cookie) ? $cookie : 'en';

        app()->setLocale($locale);

        View::share('locale', $locale);
        View::share('direction', Locales::direction($locale));

        return $next($request);
    }
}
