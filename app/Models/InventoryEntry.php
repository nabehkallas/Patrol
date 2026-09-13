<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tank_id', 'date', 'quantity_liters', 'recorded_by_id', 'notes'])]
class InventoryEntry extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity_liters' => 'decimal:3',
        ];
    }

    public function tank(): BelongsTo
    {
        // withTrashed(): a soft-deleted tank must keep resolving here for historical entries
        // that still reference it -- see Tank::historicalName().
        return $this->belongsTo(Tank::class)->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
