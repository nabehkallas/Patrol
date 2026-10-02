<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\StationCurrency;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Currencies (station admins): which currencies the station works with, each one's
 * current rate, and which is the primary currency that reports and converted totals use.
 * Day-to-day rate updates stay on the Exchange rates page.
 */
class CurrencyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/currencies', [
            'stationCurrencies' => array_map(fn (array $c) => [
                ...$c,
                'rate_to_usd' => $c['code'] === Currency::USD ? 1.0 : ExchangeRate::currentRateFor($c['code']),
                'has_rate' => $c['code'] === Currency::USD || ExchangeRate::where('currency', $c['code'])->exists(),
            ], Currency::forFrontend()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:60'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            // Units of this currency per 1 USD; USD itself is the pivot and needs none.
            'rate_to_usd' => [Rule::requiredIf($request->input('code') !== Currency::USD), 'nullable', 'numeric', 'gt:0'],
        ]);

        DB::transaction(function () use ($data, $request) {
            StationCurrency::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'symbol' => $data['symbol'] ?? null,
                'decimals' => $data['decimals'],
                'is_active' => true,
                'is_primary' => false,
            ]);

            if ($data['code'] !== Currency::USD) {
                ExchangeRate::create([
                    'currency' => $data['code'],
                    'rate_to_usd' => $data['rate_to_usd'],
                    'set_by_id' => $request->user()->id,
                    'effective_at' => now(),
                ]);
            }
        });

        Currency::forget();
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code added.', ['code' => $data['code']])]);

        return to_route('currencies.index');
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $currency = StationCurrency::findOrFail($code);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $data['is_active'] && $currency->is_primary) {
            return back()->withErrors(['is_active' => __('The primary currency cannot be turned off. Choose another primary currency first.')]);
        }

        $currency->update($data);

        Currency::forget();
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code updated.', ['code' => $currency->code])]);

        return to_route('currencies.index');
    }

    /** Makes one currency the station's primary (reporting) currency. */
    public function makePrimary(string $code): RedirectResponse
    {
        $currency = StationCurrency::findOrFail($code);

        if ($code !== Currency::USD && ! ExchangeRate::where('currency', $code)->exists()) {
            return back()->withErrors(['primary' => __('Set an exchange rate for :code before making it the primary currency.', ['code' => $code])]);
        }

        DB::transaction(function () use ($currency) {
            StationCurrency::query()->update(['is_primary' => false]);
            $currency->update(['is_primary' => true, 'is_active' => true]);
        });

        Currency::forget();
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':code is now the primary currency.', ['code' => $code])]);

        return to_route('currencies.index');
    }
}
