<?php

namespace App\Console\Commands;

use App\Models\FuelCostAllocation;
use App\Models\FuelCostLayer;
use App\Models\FuelPrice;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * One-time, explicitly-requested correction for FuelPrice rows created before the
 * FuelPriceController "midnight" timestamp bug was fixed (see resolveEffectiveAt()): a
 * same-day, live price submission was always stored with effective_at forced to midnight
 * instead of the real moment it was submitted, which then poisoned every FuelCostLayer built
 * from it and could misattribute sales recorded earlier that same real day.
 *
 * A FuelPrice counts as "a live same-day submission wrongly stored at midnight" when its
 * effective_at is exactly midnight AND falls on the same calendar day as its own created_at
 * (Eloquent's own automatic timestamp, never touched by the old bug) -- a genuine backdate has
 * a created_at on a LATER day than its effective_at, so this heuristic cannot misfire on one.
 *
 * Fixing effective_at alone isn't enough once sales have already been recorded and allocated
 * against the wrong basis, so this command also wipes and rebuilds the derived FIFO state (every
 * FuelCostLayer/FuelCostAllocation) for each affected fuel type and replays it via the
 * already-proven fuel-cost:backfill-layers command -- the same mechanism that correctly built
 * this exact production dataset's layers the first time.
 */
class FixHistoricalPriceTimestamps extends Command
{
    protected $signature = 'fuel-cost:fix-live-timestamps
        {tenant : Tenant ID}
        {--from= : Rebuild derived FIFO state from this date onward (default: start of current month)}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Correct FuelPrice rows wrongly stored at midnight for a same-day live submission, and rebuild FIFO layers/allocations to match';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (! $tenant) {
            $this->error('No tenant found with that ID.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fromOption = $this->option('from');

        tenancy()->initialize($tenant);

        $from = $fromOption ? Carbon::parse($fromOption)->startOfDay() : now()->startOfMonth();

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Fixing historical price timestamps for tenant '.$tenant->id);

        try {
            DB::transaction(function () use ($from, $tenant) {
                $affectedFuelTypeIds = $this->fixMidnightTimestamps();

                if ($affectedFuelTypeIds->isEmpty()) {
                    $this->info('No same-day price submissions wrongly stored at midnight were found.');

                    return;
                }

                $this->rebuildDerivedState($affectedFuelTypeIds, $from, $tenant->id);

                if ($this->option('dry-run')) {
                    throw new \RuntimeException('__dry_run_rollback__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__dry_run_rollback__') {
                throw $e;
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run only -- nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int> distinct fuel_type_id values touched
     */
    private function fixMidnightTimestamps(): Collection
    {
        $candidates = FuelPrice::whereTime('effective_at', '00:00:00')->get();

        $touched = collect();

        foreach ($candidates as $price) {
            if (! $price->effective_at->isSameDay($price->created_at)) {
                continue;
            }

            $this->line("  FuelPrice #{$price->id} (fuel_type {$price->fuel_type_id}): effective_at {$price->effective_at->toDateTimeString()} -> {$price->created_at->toDateTimeString()}");

            $price->update(['effective_at' => $price->created_at]);
            $touched->push($price->fuel_type_id);
        }

        return $touched->unique()->values();
    }

    /**
     * @param  Collection<int, int>  $fuelTypeIds
     */
    private function rebuildDerivedState(Collection $fuelTypeIds, CarbonInterface $from, string $tenantId): void
    {
        $allocationsDeleted = FuelCostAllocation::whereIn('fuel_type_id', $fuelTypeIds)->delete();
        $layersDeleted = FuelCostLayer::whereIn('fuel_type_id', $fuelTypeIds)->delete();

        $this->line("  Wiped {$layersDeleted} layer(s) and {$allocationsDeleted} allocation(s) for fuel type(s): ".$fuelTypeIds->implode(', '));

        $this->newLine();
        $this->info('Replaying fuel-cost:backfill-layers to rebuild...');

        Artisan::call('fuel-cost:backfill-layers', [
            'tenant' => $tenantId,
            '--from' => $from->toDateString(),
        ]);

        $this->line(Artisan::output());
    }
}
