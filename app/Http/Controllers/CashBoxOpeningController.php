<?php

namespace App\Http\Controllers;

use App\Models\CashBoxOpening;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The cash each box already held when the station started using the app: one entry per
 * currency (saving again replaces it), counted by the Cash Box as money in on its date.
 */
class CashBoxOpeningController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Currency::rule()],
            'amount' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        CashBoxOpening::updateOrCreate(
            ['currency' => $data['currency']],
            [...$data, 'recorded_by_id' => $request->user()->id],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Opening cash saved.')]);

        return back();
    }

    public function destroy(CashBoxOpening $cashBoxOpening): RedirectResponse
    {
        $cashBoxOpening->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Opening cash removed.')]);

        return back();
    }
}
