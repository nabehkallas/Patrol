<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Serialize model dates as local station time with an explicit offset
 * (e.g. 2026-09-30T00:00:00.000000+03:00) instead of Laravel's default UTC "...Z" form.
 * Both describe the same instant, but the local form keeps the calendar date in its first
 * ten characters, which the frontend relies on for date-only values such as a reading's date.
 */
trait SerializesDatesInAppTimezone
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d\TH:i:s.uP');
    }
}
