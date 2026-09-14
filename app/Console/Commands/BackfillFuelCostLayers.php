<?php

namespace App\Console\Commands;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\ExchangeRate;
use App\Models\FuelCostAllocation;
use App\Models\FuelCostLayer;
use App\Models\FuelPrice;
use App\Models\FuelType;
use App\Models\Tank;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FuelCostAllocationService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-time, explicitly-requested backfill: reconstructs the FuelCostLayer/FuelCostAllocation
 * rows that WOULD have been created by the live engine (FuelPriceController and
 * FuelCostAllocationService) had it existed at the time of past price changes and sales, for a
 * station whose price changes predate the engine's deployment. This is the retroactive
 * counterpart to the "prospective only" scope decided earlier for the live, real-time paths —
 * those are untouched by this command; it only ever inserts historical rows dated in the past,
 * for a bounded window the caller chooses (--from).
 *
 * Replays every FuelPrice change and every FuelSale transaction for each fuel type, in true
 * chronological order, as one merged timeline -- reusing FuelCostAllocationService::allocate()
 * unmodified for the sale side (it has no live-clock dependency: it just draws FIFO against
 * whatever layers/allocations already exist in the DB, which is exactly what a chronological
 * replay needs), and a --from-aware mirror of FuelPriceController::createCostLayerIfApplicable()
 * for the price-change side (historical tank inventory reconstructed as of each change, instead
 * of live "now" inventory). Every inserted row's created_at/updated_at is explicitly set to its
 * true historical moment (not "now", which is when this command actually runs) -- both so the
 * app's own anti-stacking guard (which orders by created_at) keeps working correctly, and so the
 * data doesn't lie about when it was really created.
 */
class BackfillFuelCostLayers extends Command
{
    protected $signature = 'fuel-cost:backfill-layers
        {tenant : Tenant ID to backfill}
        {--from= : Only replay price changes/sales from this date onward (default: start of current month)}
        {--dry-run : Report what would be created without writing anything}';

    protected $description = 'Retroactively reconstruct FuelCostLayer/FuelCostAllocation rows for price changes that predate the FIFO cost engine';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (! $tenant) {
            $this->error('No tenant found with that ID.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fromOption = $this->option('from');
        $from = $fromOption ? Carbon::parse($fromOption)->startOfDay() : now()->startOfMonth();

        tenancy()->initialize($tenant);

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Backfilling fuel cost layers for tenant '.$tenant->id.' from '.$from->toDateString());

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);
        $summary = [];

        try {
            DB::transaction(function () use ($from, $sypRate, &$summary) {
                foreach (FuelType::with('prices')->orderBy('name')->get() as $fuelType) {
                    $summary[$fuelType->name] = $this->backfillFuelType($fuelType, $from, $sypRate);
                }

                if ($this->option('dry-run')) {
                    throw new \RuntimeException('__dry_run_rollback__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__dry_run_rollback__') {
                throw $e;
            }
        }

        $this->newLine();
        $this->info('Summary:');

        foreach ($summary as $fuelTypeName => $result) {
            $this->line("  {$fuelTypeName}: {$result['layers_created']} layer(s), {$result['allocations_reassigned']} sale(s) re-allocated against them");

            foreach ($result['layers'] as $layer) {
                $this->line("    - {$layer['effective_from']}: {$layer['initial_liters']} L @ {$layer['cost_per_liter_syp']} SYP/L cost basis");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run only -- nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{layers_created: int, allocations_reassigned: int, layers: array<int, array<string, mixed>>}
     */
    private function backfillFuelType(FuelType $fuelType, CarbonInterface $from, float $sypRate): array
    {
        $priceChanges = FuelPrice::where('fuel_type_id', $fuelType->id)
            ->where('effective_at', '>=', $from)
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get();

        $sales = Transaction::where('fuel_type_id', $fuelType->id)
            ->where('type', TransactionType::FuelSale)
            ->where('occurred_at', '>=', $from)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        // One merged, chronologically-ordered timeline of both kinds of event. A single
        // composite numeric key (real seconds, scaled up, plus a 0/1 tiebreaker) rather than
        // Collection::sortBy()'s multi-criteria array form -- that form expects each entry as
        // [callback, direction] pairs, not bare callbacks, so passing bare callbacks silently
        // sorts on undefined behavior instead of raising an error. Price changes sort before a
        // sale at the exact same instant (the new price takes effect, then that sale is priced
        // under it), matching how the live system already behaves.
        $timeline = $priceChanges->map(fn (FuelPrice $price) => ['type' => 'price', 'at' => $price->effective_at, 'model' => $price])
            ->concat($sales->map(fn (Transaction $sale) => ['type' => 'sale', 'at' => $sale->occurred_at, 'model' => $sale]))
            ->sortBy(fn (array $event) => $event['at']->timestamp * 10 + ($event['type'] === 'price' ? 0 : 1))
            ->values();

        $layersCreated = [];
        $allocationsReassigned = 0;
        $allocationService = app(FuelCostAllocationService::class);

        foreach ($timeline as $event) {
            if ($event['type'] === 'price') {
                $layer = $this->createHistoricalLayerIfApplicable($fuelType, $event['model'], $sypRate);

                if ($layer) {
                    $layersCreated[] = [
                        'effective_from' => $layer->effective_from->toDateString(),
                        'initial_liters' => (string) $layer->initial_liters,
                        'cost_per_liter_syp' => (string) $layer->cost_per_liter_syp,
                    ];
                }

                continue;
            }

            /** @var Transaction $sale */
            $sale = $event['model'];

            // Idempotency: never touch a sale that already has allocations (either from the
            // live engine already running for it, or from a previous run of this command).
            if ($sale->costAllocations()->exists()) {
                continue;
            }

            $allocationService->allocate($sale);

            // allocate() timestamps its rows "now" (whenever this command actually runs) --
            // correct them to the sale's own real historical moment, both for an honest
            // audit trail and so the anti-stacking guard's created_at-based ordering (used by
            // createHistoricalLayerIfApplicable below, for the NEXT price change in this same
            // timeline) reflects true chronological order rather than "all backfilled in the
            // same instant".
            FuelCostAllocation::where('transaction_id', $sale->id)
                ->update(['created_at' => $sale->occurred_at, 'updated_at' => $sale->occurred_at]);

            // allocate()'s Tier-2 fallback (no historic layer to draw from) costs at
            // currentCostPerLiterSyp(), which reads $fuelType->currentPrice() -- the REAL price
            // in effect today, not at $sale->occurred_at. That's correct for the live, real-time
            // path (where "today" and "the sale's date" are the same moment), but wrong here: a
            // sale that predates the very first layer this replay creates (nothing to draw from
            // yet) would otherwise get costed at whatever the price happens to be today, not
            // what it actually was back then. Only null-layer allocations need correcting --
            // real Tier-1 draws already carry their layer's own frozen historical cost.
            $priceAtSale = $this->priceAtSaleFor($fuelType, $sale->occurred_at);

            if ($priceAtSale) {
                $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);
                $correctedCostSyp = $priceAtSale->amountInSyp($sypRate) * (1 - $marginPercent / 100);

                FuelCostAllocation::where('transaction_id', $sale->id)
                    ->whereNull('fuel_cost_layer_id')
                    ->update(['cost_per_liter_syp' => round($correctedCostSyp, 4)]);
            }

            $allocationsReassigned++;
        }

        return [
            'layers_created' => count($layersCreated),
            'allocations_reassigned' => $allocationsReassigned,
            'layers' => $layersCreated,
        ];
    }

    /**
     * Mirrors FuelPriceController::createCostLayerIfApplicable() exactly, except "now" is
     * replaced throughout by $fuelPrice->effective_at (the historical moment being replayed),
     * and tank inventory is reconstructed as of that moment instead of read live.
     */
    private function createHistoricalLayerIfApplicable(FuelType $fuelType, FuelPrice $fuelPrice, float $sypRate): ?FuelCostLayer
    {
        // Idempotency: never create a second layer for a price change already backfilled (or
        // already created live) -- without this, re-running the command after it already
        // covered a window would duplicate every layer in it.
        $alreadyBackfilled = FuelCostLayer::where('fuel_type_id', $fuelType->id)
            ->where('fuel_price_id', $fuelPrice->id)
            ->exists();

        if ($alreadyBackfilled) {
            return null;
        }

        // Nothing to diff against if this is the very first price this fuel type has ever had.
        $oldPrice = $fuelType->prices()
            ->where('id', '!=', $fuelPrice->id)
            ->where('effective_at', '<', $fuelPrice->effective_at)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if (! $oldPrice) {
            return null;
        }

        // Scoped to strictly before this price change's own moment -- otherwise, on a re-run
        // after a later price change in the same replay window has already been backfilled,
        // this would find THAT later layer instead of whatever last existed before the event
        // currently being processed, breaking the guard below.
        $lastLayer = FuelCostLayer::where('fuel_type_id', $fuelType->id)
            ->where('effective_from', '<', $fuelPrice->effective_at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        // Same guard as the live code: if nothing has sold at the about-to-be-superseded cost
        // basis since the last layer was tagged, the outstanding tank volume is still that same
        // earlier batch -- there's no new batch to snapshot.
        if ($lastLayer) {
            $soldSinceLastLayer = FuelCostAllocation::where('fuel_type_id', $fuelType->id)
                ->whereNull('fuel_cost_layer_id')
                ->where('created_at', '>=', $lastLayer->created_at)
                ->exists();

            if (! $soldSinceLastLayer) {
                return null;
            }
        }

        $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);
        $oldCostPerLiterSyp = $oldPrice->amountInSyp($sypRate) * (1 - $marginPercent / 100);

        $historicLiters = $fuelType->tanks()
            ->where('is_active', true)
            ->get()
            ->sum(fn (Tank $tank) => $this->historicalExpectedLiters($tank, $fuelPrice->effective_at));

        if ($historicLiters <= 0) {
            return null;
        }

        $layer = new FuelCostLayer([
            'fuel_type_id' => $fuelType->id,
            'fuel_price_id' => $fuelPrice->id,
            'cost_per_liter_syp' => round($oldCostPerLiterSyp, 4),
            'initial_liters' => round($historicLiters, 3),
            'effective_from' => $fuelPrice->effective_at,
        ]);
        $layer->timestamps = false;
        $layer->created_at = $fuelPrice->effective_at;
        $layer->updated_at = $fuelPrice->effective_at;
        $layer->save();

        return $layer;
    }

    /**
     * The price actually in effect on a historical date -- mirrors
     * EarningsController::priceAtSaleFor(), duplicated here rather than shared since that
     * method is private on a controller with no natural shared home, and this command's own
     * copy needs to run against $fuelType->prices() freshly (not a pre-eager-loaded collection).
     */
    private function priceAtSaleFor(FuelType $fuelType, CarbonInterface $occurredAt): ?FuelPrice
    {
        return $fuelType->prices()
            ->where('effective_at', '<=', $occurredAt)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Tank::expectedLiters(), reconstructed as of a historical moment instead of live "now" --
     * every movement type it sums, filtered to strictly before $asOf.
     */
    private function historicalExpectedLiters(Tank $tank, CarbonInterface $asOf): float
    {
        $delivered = (float) $tank->transactions()
            ->where('type', TransactionType::FuelDelivery)
            ->where('occurred_at', '<', $asOf)
            ->sum('liters');

        $sold = (float) $tank->transactions()
            ->where('type', TransactionType::FuelSale)
            ->where('occurred_at', '<', $asOf)
            ->sum('liters');

        $toppedUp = (float) $tank->topUps()
            ->where('date', '<', $asOf)
            ->sum('liters');

        $transferredIn = (float) $tank->transfersIn()
            ->where('date', '<', $asOf)
            ->sum('liters');

        $transferredOut = (float) $tank->transfersOut()
            ->where('date', '<', $asOf)
            ->sum('liters');

        return $delivered + $toppedUp + $transferredIn - $sold - $transferredOut;
    }
}
