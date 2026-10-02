<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The root URL: the public landing page for visitors. Anyone already signed in goes straight
 * to their own home instead (platform admins to the platform panel, station users to the app).
 */
class LandingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect(tenancy()->initialized ? '/cash-box' : '/platform');
        }

        return Inertia::render('welcome');
    }
}
