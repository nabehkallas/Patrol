<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'profit_margin_percent'])]
class FuelType extends Model
{
    protected function casts(): array
    {
        return [
            'profit_margin_percent' => 'decimal:2',
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
}
