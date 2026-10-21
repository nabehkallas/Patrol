<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $previous_reading_value Not a column: the pump's prior counter value, set by
 *                                            the history list for display.
 */
#[Fillable([
    'pump_id', 'shift_id', 'tank_id', 'date', 'reading_value', 'liters_sold',
    'governmental_liters', 'return_liters', 'transaction_id', 'governmental_transaction_id',
    'recorded_by_id', 'notes',
])]
class PumpCounterReading extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    /** @return list<string> */
    public static function auditContext(): array
    {
        return ['date', 'shift_id', 'pump_id'];
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reading_value' => 'integer',
            'liters_sold' => 'decimal:3',
            'governmental_liters' => 'decimal:3',
            'return_liters' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Every reading belongs to a shift; one created without saying which (a single-shift
        // station, onboarding) goes to the station's first shift.
        static::creating(function (PumpCounterReading $reading) {
            if ($reading->shift_id === null) {
                $reading->setAttribute('shift_id', Shift::defaultShift()->id);
            }
        });
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Joins the reading's shift once, for its position: readings run in order of day, then
     * shift position, then the order they were saved (several readings can share a shift, e.g.
     * a tank switch).
     *
     * @param  Builder<PumpCounterReading>  $query
     * @return Builder<PumpCounterReading>
     */
    public function scopeWithShiftPosition(Builder $query): Builder
    {
        $joined = collect($query->getQuery()->joins ?? [])->contains(fn ($join) => $join->table === 'shifts');

        return $joined ? $query : $query
            ->join('shifts', 'shifts.id', '=', 'pump_counter_readings.shift_id')
            ->select('pump_counter_readings.*');
    }

    /**
     * The order the history lists readings in: newest date first, then the last one saved first
     * (then the higher id), so it never changes between loads. Display only -- which reading
     * counts from which is always chronological().
     *
     * @param  Builder<PumpCounterReading>  $query
     * @return Builder<PumpCounterReading>
     */
    public function scopeLatestShown(Builder $query): Builder
    {
        return $query
            ->orderByRaw('date(pump_counter_readings.date) DESC')
            ->orderByDesc('pump_counter_readings.created_at')
            ->orderByDesc('pump_counter_readings.id');
    }

    /**
     * The order the meter ran in: day, shift position, then save order.
     *
     * @param  Builder<PumpCounterReading>  $query
     * @param  'asc'|'desc'  $direction
     * @return Builder<PumpCounterReading>
     */
    public function scopeChronological(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->withShiftPosition()
            ->orderBy('pump_counter_readings.date', $direction)
            ->orderBy('shifts.position', $direction)
            ->orderBy('pump_counter_readings.id', $direction);
    }

    /**
     * Readings that come before a place in the history: shift $position on $date, either at
     * its end (a new reading, $id null) or just before reading $id. whereDate(): the column
     * stores "Y-m-d H:i:s", so a bare comparison with "Y-m-d" never matches a same-day row.
     *
     * @param  Builder<PumpCounterReading>  $query
     * @return Builder<PumpCounterReading>
     */
    public function scopeBefore(Builder $query, string $date, int $position, ?int $id = null): Builder
    {
        $sameShift = function ($q) use ($position, $id) {
            $q->where('shifts.position', $position);

            if ($id !== null) {
                $q->where('pump_counter_readings.id', '<', $id);
            }
        };
        $sameDay = fn ($q) => $q->whereDate('pump_counter_readings.date', $date)
            ->where(fn ($shift) => $shift->where('shifts.position', '<', $position)->orWhere($sameShift));

        return $query->withShiftPosition()->where(fn ($q) => $q
            ->whereDate('pump_counter_readings.date', '<', $date)
            ->orWhere($sameDay));
    }

    /**
     * Readings that come after that place; see before().
     *
     * @param  Builder<PumpCounterReading>  $query
     * @return Builder<PumpCounterReading>
     */
    public function scopeAfter(Builder $query, string $date, int $position, ?int $id = null): Builder
    {
        $laterShift = function ($q) use ($position, $id) {
            $q->where('shifts.position', '>', $position);

            // A new reading goes at the end of its shift, so nothing in the same shift is after it.
            if ($id !== null) {
                $q->orWhere(fn ($same) => $same->where('shifts.position', $position)->where('pump_counter_readings.id', '>', $id));
            }
        };
        $sameDay = fn ($q) => $q->whereDate('pump_counter_readings.date', $date)->where($laterShift);

        return $query->withShiftPosition()->where(fn ($q) => $q
            ->whereDate('pump_counter_readings.date', '>', $date)
            ->orWhere($sameDay));
    }

    /**
     * @return BelongsTo<FuelPump, $this>
     */
    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'pump_id');
    }

    /**
     * @return BelongsTo<Tank, $this>
     */
    public function tank(): BelongsTo
    {
        // withTrashed(): a soft-deleted tank must keep resolving here for historical readings
        // that still reference it -- see Tank::historicalName().
        return $this->belongsTo(Tank::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function governmentalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'governmental_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
