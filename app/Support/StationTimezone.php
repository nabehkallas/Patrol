<?php

namespace App\Support;

use App\Models\Tenant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Each station keeps its own timezone in its tenant settings. While a station's tenancy is
 * active the whole app (config app.timezone, PHP's default zone and so Carbon/now()) runs in
 * that zone, so "today", day ranges in queries, report limits and the times sent to the
 * browser all follow the station's local midnight. Outside a station (platform admin, central
 * console) the configured default from config/app.php applies.
 */
class StationTimezone
{
    public const DEFAULT = 'Asia/Damascus';

    /** The timezone the app booted with, restored when a station's tenancy ends. */
    private static ?string $central = null;

    public static function for(Tenant $tenant): string
    {
        $timezone = $tenant->getAttribute('timezone');

        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (self::$central ?? config('app.timezone', self::DEFAULT));
    }

    public static function apply(Tenant $tenant): void
    {
        self::$central ??= config('app.timezone', self::DEFAULT);

        self::set(self::for($tenant));
    }

    public static function revert(): void
    {
        if (self::$central !== null) {
            self::set(self::$central);
        }
    }

    /**
     * Every standard timezone with its current UTC offset, for the settings picker.
     *
     * @return list<array{value: string, offset: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $id): array => [
                'value' => $id,
                'offset' => 'UTC'.(new DateTimeImmutable('now', new DateTimeZone($id)))->format('P'),
            ],
            DateTimeZone::listIdentifiers(),
        );
    }

    private static function set(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
