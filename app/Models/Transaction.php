<?php

namespace App\Models;

use App\Enums\DebtStatus;
use App\Enums\OtherIncomeCategory;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\Currency;
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
    'original_amount',
    'original_currency',
    'original_rate',
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
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'liters' => 'decimal:3',
            'quantity' => 'integer',
            'price_per_liter' => 'decimal:4',
            'amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'original_rate' => 'decimal:6',
            'to_amount' => 'decimal:2',
            'exchange_rate_to_usd' => 'decimal:6',
            'occurred_at' => 'datetime',
            'paid_by_sadcop' => 'boolean',
            'is_governmental' => 'boolean',
            'other_income_category' => OtherIncomeCategory::class,
        ];
    }

    /**
     * For a record paid from the primary-currency box at a rate: the amount, currency and rate it
     * was for (information only; the record itself is what left the box). Null otherwise.
     *
     * @return array{amount: float, currency: string, rate: float}|null
     */
    public function originalPayment(): ?array
    {
        return $this->original_currency !== null ? [
            'amount' => (float) $this->original_amount,
            'currency' => $this->original_currency,
            'rate' => (float) $this->original_rate,
        ] : null;
    }

    /** originalPayment() as text, "50 USD @ 139"; null when the record was paid in its own currency. */
    public function originalNote(): ?string
    {
        $original = $this->originalPayment();

        return $original !== null
            ? Currency::format($original['amount'], $original['currency']).' @ '.rtrim(rtrim(number_format($original['rate'], 6, '.', ','), '0'), '.')
            : null;
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

    /**
     * The screen that records this transaction and is the only place it can be corrected: fuel
     * sales come from pump counters, item sales and purchases from the shop, and balance
     * transfers and deliveries from Sadcop. Null for an entry made on the Transactions page.
     */
    public function managedBy(): ?string
    {
        if ($this->type === TransactionType::FuelSale) {
            return 'pump_counters';
        }

        if ($this->shop_item_id !== null) {
            return 'shop';
        }

        $hasSadcopEntry = $this->relationLoaded('sadcopLedgerEntry')
            ? $this->sadcopLedgerEntry !== null
            : $this->sadcopLedgerEntry()->exists();

        return $hasSadcopEntry ? 'sadcop' : null;
    }

    public function isPendingDebt(): bool
    {
        return $this->debt !== null && $this->debt->status === DebtStatus::Outstanding;
    }
}
