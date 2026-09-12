<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PreferencesUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/preferences');
    }

    public function update(PreferencesUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferences updated.')]);

        return to_route('preferences.edit');
    }
}
