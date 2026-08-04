<?php

namespace App\Models;

use App\Enums\SadcopLedgerEntryType;
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

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
}
