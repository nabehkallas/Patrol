<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fuel_type_id', 'price_per_liter', 'currency', 'set_by_id', 'effective_at'])]
class FuelPrice extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'price_per_liter' => 'decimal:4',
            'effective_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    /**
     * @param  Builder<FuelPrice>  $query
     * @return Builder<FuelPrice>
     */
    public function scopeEffectiveAsOf(Builder $query, CarbonInterface $at): Builder
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
