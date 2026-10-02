<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Enums\OtherIncomeCategory;
use App\Enums\TransactionType;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'type',
    'fuel_type_id',
    'tank_id',
    'pump_id',
    'shop_item_id',
    'liters',
    'quantity',
    'price_per_liter',
    'description',
    'amount',
    'currency',
    'to_currency',
    'to_amount',
    'exchange_rate_to_usd',
    'occurred_at',
    'notes',
    'paid_by_sadcop',
    'is_governmental',
    'other_income_category',
])]
class Transaction extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'currency' => Currency::class,
            'to_currency' => Currency::class,
            'liters' => 'decimal:3',
            'quantity' => 'integer',
            'price_per_liter' => 'decimal:4',
            'amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'exchange_rate_to_usd' => 'decimal:6',
            'occurred_at' => 'datetime',
            'paid_by_sadcop' => 'boolean',
            'is_governmental' => 'boolean',
            'other_income_category' => OtherIncomeCategory::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<FuelType, $this>
     */
    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    /**
     * @return BelongsTo<Tank, $this>
     */
    public function tank(): BelongsTo
    {
        // withTrashed(): a soft-deleted tank must keep resolving here for historical
        // transactions that still reference it -- see Tank::historicalName().
        return $this->belongsTo(Tank::class)->withTrashed();
    }

    /**
     * @return BelongsTo<FuelPump, $this>
     */
    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'pump_id');
    }

    /**
     * @return BelongsTo<ShopItem, $this>
     */
    public function shopItem(): BelongsTo
    {
        return $this->belongsTo(ShopItem::class);
    }

    /**
     * @return HasOne<Debt, $this>
     */
    public function debt(): HasOne
    {
        return $this->hasOne(Debt::class);
    }

    /**
     * @return HasOne<SadcopLedgerEntry, $this>
     */
    public function sadcopLedgerEntry(): HasOne
    {
        return $this->hasOne(SadcopLedgerEntry::class);
    }

    /**
     * @return HasOne<PumpCounterReading, $this>
     */
    public function pumpCounterReading(): HasOne
    {
        return $this->hasOne(PumpCounterReading::class, 'transaction_id');
    }

    /**
     * @return HasMany<FuelCostAllocation, $this>
     */
    public function costAllocations(): HasMany
    {
        return $this->hasMany(FuelCostAllocation::class);
    }

    public function isPendingDebt(): bool
    {
        return $this->debt !== null && $this->debt->status === DebtStatus::Outstanding;
    }

    public function amountInUsd(): float
    {
        $rate = $this->currency === Currency::USD
            ? 1.0
            : (float) $this->exchange_rate_to_usd;

        return $rate > 0 ? (float) $this->amount / $rate : 0.0;
    }

    public function amountInSyp(float $sypRate): float
    {
        return $this->amountInUsd() * $sypRate;
    }
}
