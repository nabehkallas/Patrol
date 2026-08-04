<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pump_id', 'tank_id', 'date', 'reading_value', 'liters_sold',
    'governmental_liters', 'return_liters', 'transaction_id', 'governmental_transaction_id',
    'recorded_by_id', 'notes',
])]
class PumpCounterReading extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reading_value' => 'decimal:3',
            'liters_sold' => 'decimal:3',
            'governmental_liters' => 'decimal:3',
            'return_liters' => 'decimal:3',
        ];
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'pump_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function governmentalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'governmental_transaction_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
