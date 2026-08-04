<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['fuel_type_id', 'price_per_liter', 'currency', 'set_by_id', 'effective_at'])]
class FuelPrice extends Model
{
    protected function casts(): array
    {
        return [
            'price_per_liter' => 'decimal:4',
            'currency' => Currency::class,
            'effective_at' => 'datetime',
        ];
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    public function scopeEffectiveAsOf($query, Carbon $at)
    {
        return $query->where('effective_at', '<=', $at);
    }

    public function amountInSyp(float $sypRate): float
    {
        $rate = $this->currency === Currency::USD
            ? 1.0
            : ExchangeRate::currentRateFor($this->currency);

        $usd = $rate > 0 ? (float) $this->price_per_liter / $rate : 0.0;

        return $usd * $sypRate;
    }
}
