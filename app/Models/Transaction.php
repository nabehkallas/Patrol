<?php

namespace App\Models;

use App\Enums\DebtStatus;
use App\Enums\OtherIncomeCategory;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\Currency;
use Carbon\CarbonInterface;
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

    /** @return list<string> */
    public static function auditContext(): array
    {
        return ['occurred_at', 'type'];
    }

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
     * When this record was actually saved. Never shown (the exact time and who saved it are in
     * the audit log); it orders the entries within a day. A fuel sale's is its reading's (the
     * sale itself is recreated whenever its reading is recalculated); any other record's is its
     * own created_at. Load pumpCounterReading and governmentalReading first.
     */
    public function savedAt(): CarbonInterface
    {
        return $this->reading()->created_at ?? $this->created_at ?? $this->occurred_at;
    }

    /**
     * What lists show for this record: the date it counts for ('Y-m-d' of occurred_at -- for a
     * fuel sale its reading's date), and for a fuel sale on a station with several shifts
     * ($withShift) its reading's shift. Load pumpCounterReading.shift and
     * governmentalReading.shift first.
     *
     * @return array{date: string, shift: string|null}
     */
    public function shownDate(bool $withShift = false): array
    {
        return [
            'date' => $this->occurred_at->toDateString(),
            'shift' => $withShift ? $this->reading()?->shift->label() : null,
        ];
    }

    /** The string scopeLatestShown() sorts by in SQL: the date, then savedAt(). */
    public function shownSortKey(): string
    {
        return $this->occurred_at->toDateString().' '.$this->savedAt()->format('Y-m-d H:i:s');
    }

    /**
     * Newest date first; within a date, the last one saved first (then the higher id), so the
     * order never changes between loads.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeLatestShown(Builder $query): Builder
    {
        return $query
            ->orderByRaw('date(transactions.occurred_at) DESC')
            ->orderByRaw("COALESCE((
                SELECT r.created_at FROM pump_counter_readings r
                WHERE transactions.type = 'fuel_sale' AND r.created_at IS NOT NULL
                    AND transactions.id IN (r.transaction_id, r.governmental_transaction_id)
                LIMIT 1
            ), transactions.created_at, transactions.occurred_at) DESC")
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
