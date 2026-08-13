<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'transaction_id',
    'direction',
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
            'direction' => DebtDirection::class,
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

    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    public function paidAmount(): float
    {
        return $this->relationLoaded('payments')
            ? (float) $this->payments->sum('amount')
            : (float) $this->payments()->sum('amount');
    }

    public function remainingAmount(): float
    {
        return max(0.0, round((float) $this->amount - $this->paidAmount(), 2));
    }

    /**
     * Records a (possibly partial) payment against this debt. Once cumulative payments cover
     * the full amount, the debt is marked settled — the same outcome a one-click "settle" now
     * produces by simply recording a payment for the whole remaining balance.
     */
    public function recordPayment(float $amount, int $userId, ?string $notes = null): DebtPayment
    {
        $payment = $this->payments()->create([
            'amount' => $amount,
            'paid_at' => now(),
            'recorded_by_id' => $userId,
            'notes' => $notes,
        ]);

        if ($this->remainingAmount() <= 0.01) {
            $this->update(['status' => DebtStatus::Settled, 'settled_at' => now()]);
        }

        return $payment;
    }

    public function amountInUsd(): float
    {
        return $this->convertToUsd((float) $this->amount);
    }

    public function amountInSyp(float $sypRate): float
    {
        return $this->amountInUsd() * $sypRate;
    }

    public function remainingAmountInUsd(): float
    {
        return $this->convertToUsd($this->remainingAmount());
    }

    public function remainingAmountInSyp(float $sypRate): float
    {
        return $this->remainingAmountInUsd() * $sypRate;
    }

    private function convertToUsd(float $amount): float
    {
        $rate = $this->currency === Currency::USD
            ? 1.0
            : (float) $this->exchange_rate_to_usd;

        return $rate > 0 ? $amount / $rate : 0.0;
    }
}
