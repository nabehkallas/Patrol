<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fuel_type_id', 'fuel_price_id', 'cost_per_liter_syp', 'initial_liters', 'effective_from'])]
class FuelCostLayer extends Model
{
    protected function casts(): array
    {
        return [
            'cost_per_liter_syp' => 'decimal:4',
            'initial_liters' => 'decimal:3',
            'effective_from' => 'datetime',
        ];
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function fuelPrice(): BelongsTo
    {
        return $this->belongsTo(FuelPrice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FuelCostAllocation::class);
    }

    /**
     * Liters not yet drawn from this layer -- derived from allocations still pointing at it
     * (never a stored/mutated counter), so editing or deleting a sale automatically "returns"
     * its share of the layer without any extra bookkeeping.
     */
    public function remainingLiters(): float
    {
        return max(0.0, (float) $this->initial_liters - (float) $this->allocations()->sum('liters'));
    }
}
