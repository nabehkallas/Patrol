<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A station's shift (Settings > Shifts). Every station has at least one; a station with a
 * single active shift never sees a shift selector. Pump readings belong to a date + shift and
 * run in order of day, then shift position, so a shift's position never changes once set: new
 * shifts go at the end, and a shift in use is deactivated rather than deleted. A night shift
 * past midnight still counts on its starting day; its times only pick the default shift.
 *
 * @property int $id
 * @property int $position
 * @property string|null $name
 * @property string|null $starts_at "HH:MM"
 * @property string|null $ends_at "HH:MM"
 * @property bool $is_active
 */
#[Fillable(['position', 'name', 'starts_at', 'ends_at', 'is_active'])]
class Shift extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PumpCounterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(PumpCounterReading::class);
    }

    /**
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The first active shift (the only one on a single-shift station), created if none exists. */
    public static function defaultShift(): self
    {
        return static::active()->orderBy('position')->first()
            ?? static::orderBy('position')->first()
            ?? static::create(['position' => 1, 'is_active' => true]);
    }

    /** More than one active shift: only then do shifts show anywhere. */
    public static function severalActive(): bool
    {
        return static::active()->count() > 1;
    }

    /** Its name, or "Shift 2" when none was given. */
    public function label(): string
    {
        return $this->name ?: __('Shift :number', ['number' => $this->position]);
    }

    /**
     * @return array{id: int, position: int, name: string, starts_at: string|null, ends_at: string|null, is_active: bool}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'name' => $this->label(),
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_active' => $this->is_active,
        ];
    }
}
