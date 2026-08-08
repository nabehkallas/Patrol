<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_tank_id', 'to_tank_id', 'liters', 'date', 'recorded_by_id', 'notes'])]
class TankTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'liters' => 'decimal:3',
        ];
    }

    public function fromTank(): BelongsTo
    {
        return $this->belongsTo(Tank::class, 'from_tank_id');
    }

    public function toTank(): BelongsTo
    {
        return $this->belongsTo(Tank::class, 'to_tank_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
