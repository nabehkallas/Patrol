<?php

namespace App\Http\Controllers;

use App\Models\CashBoxOpening;
use App\Support\Currency;
use App\Support\EntryRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > "Cash when you started using the app" (admins): the cash each box already held
 * before the station recorded anything, one entry per currency (saving again replaces it),
 * counted by the Cash Box as money in on its date -- never income. Not for cash that was
 * already recorded as a deposit or income: that would count it twice.
 */
class CashBoxOpeningController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/opening-cash', [
            'openings' => CashBoxOpening::orderBy('currency')->get(['id', 'currency', 'amount', 'date', 'notes']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Currency::rule()],
            'amount' => ['required', ...EntryRules::amount('0')],
            'date' => ['required', ...EntryRules::date($request->user())],
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
