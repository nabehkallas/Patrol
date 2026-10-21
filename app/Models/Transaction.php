<?php

namespace App\Models;

use App\Enums\DebtStatus;
use App\Enums\OtherIncomeCategory;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * When this record shows as having happened. A fuel sale is stored at noon of its reading's
     * date (the day it counts for in every total), which is no time anyone typed: saved on that
     * same day, it shows when its reading was saved; saved on another day, it shows only the
     * date, with when it was entered (and its shift, when asked for) under it. `at` is then a
     * bare 'Y-m-d'. Load pumpCounterReading and governmentalReading first.
     *
     * @return array{at: string, entered: array{at: string, shift: string|null}|null}
     */
    public function shownTime(bool $withShift = false): array
    {
        $reading = $this->reading();
        $saved = $reading?->created_at;

        if ($reading === null || $saved === null) {
            return ['at' => $this->occurred_at->toIso8601String(), 'entered' => null];
        }

        if ($saved->isSameDay($this->occurred_at)) {
            return ['at' => $saved->toIso8601String(), 'entered' => null];
        }

        return ['at' => $this->occurred_at->toDateString(), 'entered' => [
            'at' => $saved->toIso8601String(),
            'shift' => $withShift ? $reading->shift->label() : null,
        ]];
    }

    /** Shown as its date only, with when it was entered (see shownTime()). */
    public function enteredLate(): bool
    {
        return $this->shownTime()['entered'] !== null;
    }

    /**
     * What shownTime() sorts by, as the same string scopeLatestShown() builds in SQL: the shown
     * time, and for a sale entered on a later day the end of its date, then when it was entered.
     */
    public function shownSortKey(): string
    {
        $saved = $this->reading()?->created_at;

        if ($saved === null) {
            return $this->occurred_at->format('Y-m-d H:i:s');
        }

        return $saved->isSameDay($this->occurred_at)
            ? $saved->format('Y-m-d H:i:s')
            : $this->occurred_at->toDateString().' 23:59:59|'.$saved->format('Y-m-d H:i:s');
    }

    /**
     * Newest first by shownSortKey().
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeLatestShown(Builder $query): Builder
    {
        return $query
            ->orderByRaw("COALESCE((
                SELECT CASE WHEN date(r.created_at) = date(transactions.occurred_at) THEN r.created_at
                    ELSE date(transactions.occurred_at) || ' 23:59:59|' || r.created_at END
                FROM pump_counter_readings r
                WHERE transactions.type = 'fuel_sale' AND r.created_at IS NOT NULL
                    AND transactions.id IN (r.transaction_id, r.governmental_transaction_id)
                LIMIT 1
            ), transactions.occurred_at) DESC")
            ->orderByDesc('transactions.id');
    }

    /** The pump reading a fuel sale came from (its normal or its governmental part). */
    public function reading(): ?PumpCounterReading
    {
        return $this->type === TransactionType::FuelSale
            ? ($this->pumpCounterReading ?? $this->governmentalReading)
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
     * @return HasOne<PumpCounterReading, $this>
     */
    public function governmentalReading(): HasOne
    {
        return $this->hasOne(PumpCounterReading::class, 'governmental_transaction_id');
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
