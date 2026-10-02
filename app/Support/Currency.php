<?php

namespace App\Support;

use App\Models\StationCurrency;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Currencies are plain ISO-style codes ("SYP", "USD", "EUR", or a station's own custom code),
 * stored as strings on every money column. Which ones a station uses, and which is its primary
 * (reporting) currency, live in its `currencies` table (StationCurrency).
 *
 * USD is the conversion pivot: every rate is stored as "units per 1 USD" (ExchangeRate), so any
 * currency converts to any other through USD.
 */
final class Currency
{
    /** The conversion pivot; its rate is always 1. */
    public const USD = 'USD';

    /** Sadcop (the state supplier) bills in Syrian pounds, and fuel cost layers are kept in it. */
    public const SYP = 'SYP';

    /** @var array<string, array<string, StationCurrency>> per station, for this request */
    private static array $cache = [];

    /**
     * All currencies the station has defined, active or not, keyed by code.
     *
     * @return array<string, StationCurrency>
     */
    public static function all(): array
    {
        $key = (string) (tenant('id') ?? 'central');

        if (! isset(self::$cache[$key])) {
            self::$cache[$key] = Schema::hasTable('currencies')
                ? StationCurrency::query()->orderByDesc('is_primary')->orderBy('code')->get()->keyBy('code')->all()
                : [];
        }

        return self::$cache[$key];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * Codes that can be chosen for new entries.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = array_values(array_map(
            fn (StationCurrency $c) => $c->code,
            array_filter(self::all(), fn (StationCurrency $c) => $c->is_active),
        ));

        return $codes !== [] ? $codes : [self::SYP, self::USD, 'TRY'];
    }

    /** The station's primary (reporting) currency. */
    public static function primary(): string
    {
        foreach (self::all() as $currency) {
            if ($currency->is_primary) {
                return $currency->code;
            }
        }

        return self::SYP;
    }

    public static function decimals(string $code): int
    {
        return self::all()[$code]->decimals ?? ($code === self::SYP ? 0 : 2);
    }

    /** Validation: one of the station's active currencies. */
    public static function rule(): In
    {
        return Rule::in(self::codes());
    }

    /**
     * For the frontend: every currency the station has (inactive ones are still needed to
     * display old records), with the primary flagged.
     *
     * @return list<array{code: string, name: string, symbol: string|null, decimals: int, is_active: bool, is_primary: bool}>
     */
    public static function forFrontend(): array
    {
        return array_values(array_map(fn (StationCurrency $c) => [
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'decimals' => $c->decimals,
            'is_active' => $c->is_active,
            'is_primary' => $c->is_primary,
        ], self::all()));
    }
}
