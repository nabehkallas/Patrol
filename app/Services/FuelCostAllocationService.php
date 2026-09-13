<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\ExchangeRate;
use App\Models\FuelCostAllocation;
use App\Models\FuelCostLayer;
use App\Models\FuelType;
use App\Models\Transaction;

/**
 * FIFO cost allocation for fuel sales, prospective from whenever this feature shipped --
 * historical sales recorded before a fuel type's first FuelCostLayer existed are never
 * allocated, and EarningsController falls back to its older margin-based estimate for those.
 *
 * A sale's liters are drawn from the oldest FuelCostLayer with liters still remaining (Tier 1,
 * FIFO), splitting across layers -- and finally the fuel type's current cost basis (Tier 2) --
 * as needed. Allocation happens once, at the moment the sale is recorded, against whatever
 * layers exist *right then*; it does not retroactively replay history for backdated entries.
 */
class FuelCostAllocationService
{
    /**
     * Allocates $transaction's liters against current FIFO layers and records the result.
     * Safe to call on a transaction that already has allocations (e.g. after reverse()) --
     * it always starts from that transaction having none.
     */
    public function allocate(Transaction $transaction): void
    {
        if ($transaction->fuel_type_id === null || $transaction->liters === null) {
            return;
        }

        $remaining = (float) $transaction->liters;

        if ($remaining <= 0) {
            return;
        }

        $fuelType = $transaction->fuelType ?? FuelType::find($transaction->fuel_type_id);

        if (! $fuelType) {
            return;
        }

        $layers = FuelCostLayer::where('fuel_type_id', $fuelType->id)
            ->orderBy('effective_from')
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $available = $layer->remainingLiters();

            if ($available <= 0) {
                continue;
            }

            $drawn = min($available, $remaining);

            FuelCostAllocation::create([
                'transaction_id' => $transaction->id,
                'fuel_type_id' => $fuelType->id,
                'fuel_cost_layer_id' => $layer->id,
                'liters' => round($drawn, 3),
                'cost_per_liter_syp' => $layer->cost_per_liter_syp,
            ]);

            $remaining -= $drawn;
        }

        if ($remaining > 0.0005) {
            FuelCostAllocation::create([
                'transaction_id' => $transaction->id,
                'fuel_type_id' => $fuelType->id,
                'fuel_cost_layer_id' => null,
                'liters' => round($remaining, 3),
                'cost_per_liter_syp' => round($this->currentCostPerLiterSyp($fuelType), 4),
            ]);
        }
    }

    /**
     * Deletes every allocation for $transaction -- call before allocate() when a sale's liters
     * or fuel type changed, so it re-allocates cleanly against the layers' now-correct
     * remaining volume, and before deleting the sale itself so the layers it drew from show
     * that volume as available again.
     */
    public function reverse(Transaction $transaction): void
    {
        FuelCostAllocation::where('transaction_id', $transaction->id)->delete();
    }

    /**
     * Today's cost basis for a fuel type: current selling price x (1 - margin%) -- the same
     * formula a new FuelCostLayer freezes at the moment of a price change, just evaluated live
     * for whatever hasn't been tagged into a historic layer yet.
     */
    public function currentCostPerLiterSyp(FuelType $fuelType): float
    {
        $currentPrice = $fuelType->currentPrice();

        if (! $currentPrice) {
            return 0.0;
        }

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);
        $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);

        return $currentPrice->amountInSyp($sypRate) * (1 - $marginPercent / 100);
    }
}
