<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fuel_type_id', 'name', 'capacity_liters'])]
class Tank extends Model
{
    protected function casts(): array
    {
        return [
            'capacity_liters' => 'decimal:3',
        ];
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function inventoryEntries(): HasMany
    {
        return $this->hasMany(InventoryEntry::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function topUps(): HasMany
    {
        return $this->hasMany(TankTopUp::class);
    }

    public function expectedLiters(): float
    {
        $delivered = (float) $this->transactions()->where('type', TransactionType::FuelDelivery)->sum('liters');
        $sold = (float) $this->transactions()->where('type', TransactionType::FuelSale)->sum('liters');
        $toppedUp = (float) $this->topUps()->sum('liters');

        return $delivered + $toppedUp - $sold;
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

    public function summary(): array
    {
        $latest = $this->latestReading();
        $expected = $this->expectedLiters();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity_liters' => $this->capacity_liters,
            'fuel_type' => $this->fuelType->only(['id', 'name']),
            'expected_liters' => round($expected, 3),
            'latest_reading' => $latest?->only(['date', 'quantity_liters']),
            'variance_liters' => $latest ? round((float) $latest->quantity_liters - $expected, 3) : null,
        ];
    }
}
