<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['debt_id', 'amount', 'cash_amount', 'cash_currency', 'cash_rate', 'paid_at', 'recorded_by_id', 'notes'])]
class DebtPayment extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    /** @return list<string> */
    public static function auditContext(): array
    {
        return ['paid_at', 'debt_id'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'cash_rate' => 'decimal:6',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * The currency the cash moved in: the debt's own, unless the payment was made from another
     * box (a USD debt paid from the SYP box at a rate) -- then that box's.
     */
    public function cashCurrency(): string
    {
        return $this->cash_currency ?? $this->debt->currency;
    }

    /** The cash that moved, in cashCurrency(); see there. */
    public function cashAmount(): float
    {
        return (float) ($this->cash_amount ?? $this->amount);
    }

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
