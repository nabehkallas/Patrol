<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id',
    'debtor_id',
    'fuel_type_id',
    'liters',
    'price_per_liter',
    'amount',
    'currency',
    'exchange_rate_to_usd',
    'date',
    'details',
    'status',
    'recorded_by_id',
    'settled_at',
])]
class Debt extends Model
{
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'status' => DebtStatus::class,
            'amount' => 'decimal:2',
            'liters' => 'decimal:3',
            'price_per_liter' => 'decimal:4',
            'exchange_rate_to_usd' => 'decimal:6',
            'date' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
