<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFuelPriceRequest;
use App\Http\Requests\Admin\UpdateFuelPriceRequest;
use App\Http\Requests\Admin\UpdateFuelTypeProfitMarginRequest;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelType;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FuelPriceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', FuelPrice::class);

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        return Inertia::render('admin/fuel-prices/index', [
            'fuelTypes' => FuelType::orderBy('name')->get(['id', 'name', 'slug', 'profit_margin_percent'])
                ->map(function (FuelType $fuelType) use ($sypRate) {
                    $currentPrice = $fuelType->currentPrice();

                    return [
                        'id' => $fuelType->id,
                        'name' => $fuelType->name,
                        'slug' => $fuelType->slug,
                        'profit_margin_percent' => $fuelType->profit_margin_percent,
                        'current_price_syp' => $currentPrice ? round($currentPrice->amountInSyp($sypRate), 3) : null,
                    ];
                }),
            'prices' => FuelPrice::with(['fuelType', 'setBy'])
                ->latest('effective_at')
                ->paginate(25),
        ]);
    }

    public function updateProfitMargin(UpdateFuelTypeProfitMarginRequest $request, FuelType $fuelType): RedirectResponse
    {
        $this->authorize('update', $fuelType);

        $fuelType->update(['profit_margin_percent' => $request->validated('profit_margin_percent')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profit margin updated.')]);

        return to_route('admin.fuel-prices.index');
    }

    public function store(StoreFuelPriceRequest $request): RedirectResponse
    {
        $this->authorize('create', FuelPrice::class);

        $data = $request->validated();
        $data['set_by_id'] = $request->user()->id;
        $data['effective_at'] = isset($data['effective_at'])
            ? Carbon::parse($data['effective_at'])->startOfDay()
            : now();

        $fuelPrice = FuelPrice::create($data);

        $repriced = $this->repriceTransactions($fuelPrice->fuelType, $fuelPrice->effective_at);

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->withRepriceNote(__('Fuel price updated.'), $repriced)]);

        return to_route('admin.fuel-prices.index');
    }

    public function update(UpdateFuelPriceRequest $request, FuelPrice $fuelPrice): RedirectResponse
    {
        $this->authorize('update', $fuelPrice);

        $data = $request->validated();
        $oldFuelType = $fuelPrice->fuelType;
        $oldEffectiveAt = $fuelPrice->effective_at;

        $data['effective_at'] = isset($data['effective_at'])
            ? Carbon::parse($data['effective_at'])->startOfDay()
            : $oldEffectiveAt;

        $fuelPrice->update($data);
        $fuelPrice->refresh();

        // A price correction almost always keeps the same fuel type — only that one window
        // (the earlier of the old/new effective_at through now) needs repricing. Reassigning
        // the price to a different fuel type is rarer but must reprice both: the old fuel
        // type's window it no longer governs, and the new fuel type's window it now does.
        if ($fuelPrice->fuel_type_id === $oldFuelType->id) {
            $repriced = $this->repriceTransactions($oldFuelType, $oldEffectiveAt->min($fuelPrice->effective_at));
        } else {
            $repriced = $this->repriceTransactions($oldFuelType, $oldEffectiveAt)
                + $this->repriceTransactions($fuelPrice->fuelType, $fuelPrice->effective_at);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->withRepriceNote(__('Fuel price updated.'), $repriced)]);

        return to_route('admin.fuel-prices.index');
    }

    public function destroy(FuelPrice $fuelPrice): RedirectResponse
    {
        $this->authorize('delete', $fuelPrice);

        $fuelType = $fuelPrice->fuelType;
        $effectiveAt = $fuelPrice->effective_at;

        $fuelPrice->delete();

        $repriced = $this->repriceTransactions($fuelType, $effectiveAt);

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->withRepriceNote(__('Fuel price deleted.'), $repriced)]);

        return to_route('admin.fuel-prices.index');
    }

    /**
     * Rewrites price_per_liter/amount (and any linked debt's amount) on every fuel-sale
     * transaction for $fuelType occurring on or after $from, to match whatever price is
     * correctly effective as of each transaction's own date — used to fix already-recorded
     * sales after a price is added, corrected, or removed for a past date. Liters are never
     * touched, so this updates rows in place rather than deleting/recreating them.
     */
    private function repriceTransactions(FuelType $fuelType, CarbonInterface $from): int
    {
        $transactions = Transaction::where('fuel_type_id', $fuelType->id)
            ->where('type', TransactionType::FuelSale)
            ->where('occurred_at', '>=', $from)
            ->with('debt')
            ->get();

        $repriced = 0;

        foreach ($transactions as $transaction) {
            $priceAsOf = $fuelType->priceAsOf($transaction->occurred_at);
            $newPricePerLiter = $priceAsOf ? (string) $priceAsOf->price_per_liter : '0.0000';

            if ($newPricePerLiter === (string) $transaction->price_per_liter) {
                continue;
            }

            $amount = round((float) $transaction->liters * (float) $newPricePerLiter, 2);

            $transaction->update([
                'price_per_liter' => $newPricePerLiter,
                'amount' => $amount,
            ]);

            $transaction->debt?->update(['amount' => $amount]);

            $repriced++;
        }

        return $repriced;
    }

    private function withRepriceNote(string $message, int $repriced): string
    {
        if ($repriced === 0) {
            return $message;
        }

        return $message.' '.__(':count transaction(s) updated to match.', ['count' => $repriced]);
    }
}
