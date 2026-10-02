<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['currency', 'rate_to_usd', 'set_by_id', 'effective_at'])]
class ExchangeRate extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'rate_to_usd' => 'decimal:6',
            'effective_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    public static function currentRateFor(Currency $currency): float
    {
        if ($currency === Currency::USD) {
            return 1.0;
        }

        $rate = static::query()
            ->where('currency', $currency)
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->first();

        return $rate ? (float) $rate->rate_to_usd : 1.0;
    }
}
