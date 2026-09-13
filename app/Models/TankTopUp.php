<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tank_id', 'liters', 'date', 'recorded_by_id', 'notes', 'is_opening_balance'])]
class TankTopUp extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'liters' => 'decimal:3',
            'is_opening_balance' => 'boolean',
        ];
    }

    public function tank(): BelongsTo
    {
        // withTrashed(): a soft-deleted tank must keep resolving here for historical top-ups
        // that still reference it -- see Tank::historicalName().
        return $this->belongsTo(Tank::class)->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
