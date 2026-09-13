<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transaction_id', 'fuel_type_id', 'fuel_cost_layer_id', 'liters', 'cost_per_liter_syp'])]
class FuelCostAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'liters' => 'decimal:3',
            'cost_per_liter_syp' => 'decimal:4',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function fuelCostLayer(): BelongsTo
    {
        return $this->belongsTo(FuelCostLayer::class);
    }
}
