<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'fuel_type_id'])]
class FuelPump extends Model
{
    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function counterReadings(): HasMany
    {
        return $this->hasMany(PumpCounterReading::class, 'pump_id');
    }
}
