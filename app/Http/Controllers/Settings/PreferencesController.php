<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PreferencesUpdateRequest;
use App\Support\StationTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/preferences', [
            'timezones' => StationTimezone::options(),
        ]);
    }

    public function update(PreferencesUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferences updated.')]);

        return to_route('preferences.edit');
    }

    /** Station-wide: changes the day boundaries every user of this station works with. */
    public function updateTimezone(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
        ]);

        tenant()->update(['timezone' => $data['timezone']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferences updated.')]);

        return to_route('preferences.edit');
    }
}
