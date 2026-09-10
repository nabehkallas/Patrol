<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'profit_margin_percent'])]
class FuelType extends Model
{
    protected function casts(): array
    {
        return [
            'profit_margin_percent' => 'decimal:6',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FuelPrice::class);
    }

    public function tanks(): HasMany
    {
        return $this->hasMany(Tank::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function inventoryEntries(): HasMany
    {
        return $this->hasMany(InventoryEntry::class);
    }

    public function currentPrice(): ?FuelPrice
    {
        return $this->prices()
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->first();
    }

    /**
     * The price in effect on a given date — e.g. so a pump reading dated in the past is charged
     * at the price that actually applied then, not whatever's current right now. A date-only
     * correction is stored at startOfDay(), so two corrections entered for the same date share
     * an identical effective_at — id breaks the tie in entry order.
     */
    public function priceAt(CarbonInterface $date): ?FuelPrice
    {
        return $this->prices()
            ->effectiveAsOf($date)
            ->latest('effective_at')
            ->latest('id')
            ->first();
    }
}
