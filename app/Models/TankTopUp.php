<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tank_id', 'liters', 'date', 'recorded_by_id', 'notes'])]
class TankTopUp extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'liters' => 'decimal:3',
        ];
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
