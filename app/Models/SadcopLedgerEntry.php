<?php

namespace App\Models;

use App\Enums\SadcopLedgerEntryType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type',
    'transaction_id',
    'amount',
    'liters',
    'price_per_liter',
    'recorded_by_id',
    'occurred_at',
    'notes',
])]
class SadcopLedgerEntry extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'type' => SadcopLedgerEntryType::class,
            'amount' => 'decimal:2',
            'liters' => 'decimal:3',
            'price_per_liter' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public static function currentBalanceSyp(): float
    {
        $credits = static::whereIn('type', [SadcopLedgerEntryType::Opening, SadcopLedgerEntryType::Deposit])->sum('amount');
        $debits = static::where('type', SadcopLedgerEntryType::Delivery)->sum('amount');

        return (float) $credits - (float) $debits;
    }

    /** The balance as it stood just before $moment: every entry earlier than it, none after. */
    public static function balanceSypBefore(CarbonInterface $moment): float
    {
        $credits = static::whereIn('type', [SadcopLedgerEntryType::Opening, SadcopLedgerEntryType::Deposit])
            ->where('occurred_at', '<', $moment)
            ->sum('amount');
        $debits = static::where('type', SadcopLedgerEntryType::Delivery)
            ->where('occurred_at', '<', $moment)
            ->sum('amount');

        return (float) $credits - (float) $debits;
    }
}
