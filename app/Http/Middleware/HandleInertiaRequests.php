<?php

namespace App\Http\Middleware;

use App\Models\FuelType;
use App\Support\Currency;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // A platform admin is an authenticated user with no active tenant — their `User` row
        // lives in the central database, which has no Spatie permission tables, so `isAdmin()`
        // (which queries those tables) must never be called for them.
        $isSuperAdmin = $request->user() !== null && ! tenancy()->initialized;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // The station's timezone (its own setting while tenancy is active), so the frontend shows times and works out "today" in
            // station time whatever timezone the viewer's own device is set to.
            'timezone' => config('app.timezone'),
            'auth' => [
                'user' => $request->user(),
                'isAdmin' => ! $isSuperAdmin && ($request->user()?->isAdmin() ?? false),
                'isSuperAdmin' => $isSuperAdmin,
            ],
            'tenant' => tenancy()->initialized ? ['name' => tenant('name')] : null,
            // The station's currencies and its primary (reporting) currency, for pickers and formatting.
            'currencies' => tenancy()->initialized ? Currency::forFrontend() : [],
            'primaryCurrency' => tenancy()->initialized ? Currency::primary() : Currency::SYP,
            // Each fuel type's own colour (dots, card edges, badges, chart lines all use it).
            'fuelTypeColors' => fn () => tenancy()->initialized && $request->user()
                ? FuelType::orderBy('id')->get(['id', 'name', 'color'])
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
