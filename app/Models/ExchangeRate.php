<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['currency', 'rate_to_usd', 'set_by_id', 'effective_at'])]
class ExchangeRate extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
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

    public static function currentRateFor(string $currency): float
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

    /**
     * Converts an amount between any two of the station's currencies at today's rates, through
     * USD (every rate is "units per 1 USD"). For figures that have no recorded rate of their
     * own, such as a cash box balance that mixes many days.
     */
    public static function convert(float $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return $amount;
        }

        $fromRate = static::currentRateFor($from);

        return $fromRate > 0 ? $amount / $fromRate * static::currentRateFor($to) : 0.0;
    }

    /**
     * A per-currency breakdown converted into one currency and summed.
     *
     * @param  array<string, float>  $breakdown
     */
    public static function convertBreakdown(array $breakdown, string $to): float
    {
        $total = 0.0;

        foreach ($breakdown as $currency => $amount) {
            $total += static::convert((float) $amount, (string) $currency, $to);
        }

        return round($total, Currency::decimals($to));
    }
}
