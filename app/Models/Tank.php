<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['fuel_type_id', 'name', 'capacity_liters', 'is_active'])]
class Tank extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'capacity_liters' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FuelType, $this>
     */
    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    /**
     * @return HasMany<InventoryEntry, $this>
     */
    public function inventoryEntries(): HasMany
    {
        return $this->hasMany(InventoryEntry::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<TankTopUp, $this>
     */
    public function topUps(): HasMany
    {
        return $this->hasMany(TankTopUp::class);
    }

    /**
     * @return HasMany<TankTransfer, $this>
     */
    public function transfersIn(): HasMany
    {
        return $this->hasMany(TankTransfer::class, 'to_tank_id');
    }

    /**
     * @return HasMany<TankTransfer, $this>
     */
    public function transfersOut(): HasMany
    {
        return $this->hasMany(TankTransfer::class, 'from_tank_id');
    }

    public function expectedLiters(): float
    {
        $movement = $this->movement();

        return $movement['in'] - $movement['out'];
    }

    /**
     * Liters that came into the tank (deliveries, top-ups, transfers in) and went out of it
     * (sales, transfers out) between two days, both included. A missing bound leaves that side
     * open, so movement() with no dates covers the tank's whole history.
     *
     * @return array{in: float, out: float}
     */
    public function movement(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $transactions = fn (TransactionType $type) => (float) $this->transactions()
            ->where('type', $type)
            ->when($from, fn (Builder $query) => $query->where('occurred_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn (Builder $query) => $query->where('occurred_at', '<=', $to->copy()->endOfDay()))
            ->sum('liters');
        $dated = fn (HasMany $relation) => (float) $relation
            ->when($from, fn (Builder $query) => $query->whereDate('date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $query) => $query->whereDate('date', '<=', $to->toDateString()))
            ->sum('liters');

        return [
            'in' => $transactions(TransactionType::FuelDelivery) + $dated($this->topUps()) + $dated($this->transfersIn()),
            'out' => $transactions(TransactionType::FuelSale) + $dated($this->transfersOut()),
        ];
    }

    /**
     * The tank over a date range: liters at the start of the first day, in and out during it, and
     * liters at the end of the last day.
     *
     * @return array{starting: float, in: float, out: float, ending: float}
     */
    public function periodSummary(CarbonInterface $from, CarbonInterface $to): array
    {
        $before = $this->movement(to: $from->copy()->subDay());
        $during = $this->movement($from, $to);
        $starting = $before['in'] - $before['out'];

        return [
            'starting' => round($starting, 3),
            'in' => round($during['in'], 3),
            'out' => round($during['out'], 3),
            'ending' => round($starting + $during['in'] - $during['out'], 3),
        ];
    }

    /**
     * How many more liters this tank can physically accept right now, based on its capacity
     * and the expected (theoretical) stock currently in it.
     */
    public function remainingCapacity(): float
    {
        return max(0.0, (float) $this->capacity_liters - $this->expectedLiters());
    }

    public function latestReading(): ?InventoryEntry
    {
        return $this->inventoryEntries()->latest('date')->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $latest = $this->latestReading();
        $expected = $this->expectedLiters();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity_liters' => $this->capacity_liters,
            'is_active' => $this->is_active,
            'fuel_type' => $this->fuelType->only(['id', 'name']),
            'expected_liters' => round($expected, 3),
            'latest_reading' => $latest?->only(['date', 'quantity_liters']),
            'variance_liters' => $latest ? round((float) $latest->quantity_liters - $expected, 3) : null,
        ];
    }
}
