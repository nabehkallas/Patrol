<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cash a box already held when the station started using the app -- one per currency,
 * counted as money in on its date by the Cash Box only (not income: Earnings, Statistics and
 * the annual summary never see it).
 */
#[Fillable(['currency', 'amount', 'date', 'recorded_by_id', 'notes'])]
class CashBoxOpening extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
