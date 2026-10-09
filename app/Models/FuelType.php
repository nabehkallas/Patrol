<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\FuelColors;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'color', 'profit_margin_percent'])]
class FuelType extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    /** A new fuel type gets the next colour nobody uses yet, unless one was chosen. */
    protected static function booted(): void
    {
        static::creating(function (FuelType $fuelType) {
            $fuelType->color ??= FuelColors::nextFree();
        });
    }

    protected function casts(): array
    {
        return [
            'profit_margin_percent' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<FuelPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(FuelPrice::class);
    }

    /**
     * @return HasMany<Tank, $this>
     */
    public function tanks(): HasMany
    {
        return $this->hasMany(Tank::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<InventoryEntry, $this>
     */
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
