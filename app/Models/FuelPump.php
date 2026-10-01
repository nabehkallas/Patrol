<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class FuelPump extends Model
{
    use SerializesDatesInAppTimezone;

    public function fuelTypes(): BelongsToMany
    {
        return $this->belongsToMany(FuelType::class, 'fuel_pump_fuel_type', 'pump_id', 'fuel_type_id');
    }

    public function counterReadings(): HasMany
    {
        return $this->hasMany(PumpCounterReading::class, 'pump_id');
    }
}
