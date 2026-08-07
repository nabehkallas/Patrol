<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleLocale;
use App\Http\Middleware\InitializeTenancyFromSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fly.io terminates TLS at its edge and forwards plain HTTP to the container, so
        // without this Laravel thinks every request is insecure (wrong scheme in generated
        // asset/route URLs, session cookies not marked secure, etc). Fly's proxy is the only
        // thing that can reach the container, so trusting all proxies here is safe.
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        $middleware->web(append: [
            InitializeTenancyFromSession::class,
            HandleAppearance::class,
            HandleLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // Must run after the session starts (so it can read `tenant_id`) but before
        // `Authenticate` resolves the session's user — otherwise a tenant user's session gets
        // looked up against the central `users` table (whatever the default connection still
        // is) instead of their own station's, since IDs aren't unique across databases. The
        // priority list anchors on the *interface* Authenticate implements, not the concrete
        // class — anchoring on the concrete class silently no-ops (falls through to the end).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: InitializeTenancyFromSession::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
