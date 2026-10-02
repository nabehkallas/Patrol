<?php

namespace App\Concerns;

use App\Models\Debt;
use App\Models\Transaction;
use App\Support\Currency;
use Illuminate\Support\Collection;

trait GroupsByCurrency
{
    /**
     * Groups raw (unconverted) amounts by their actual recorded currency — money totals should
     * show what's physically in each currency, not everything blended into one converted
     * figure. Always includes the station's primary currency first (even if zero, for a
     * consistent main figure); other currencies are included only when non-zero.
     *
     * @template TItem of Transaction|Debt|\stdClass
     *
     * @param  Collection<array-key, TItem>  $items
     * @param  string|\Closure  $amount  Attribute name (default 'amount') or a per-item resolver —
     *                                   e.g. a debt's remaining balance instead of its full amount.
     * @return array<string, float>
     */
    protected function byCurrency(Collection $items, string|\Closure $amount = 'amount'): array
    {
        $totals = $items
            ->groupBy(fn (object $item): string => $item->currency)
            ->map(fn ($group) => (float) $group->sum($amount))
            ->all();

        $round = fn (string $currency, float $amount) => round($amount, Currency::decimals($currency));
        $primary = Currency::primary();

        $result = [$primary => $round($primary, $totals[$primary] ?? 0.0)];

        foreach ($totals as $currency => $amount) {
            if ($currency !== $primary && $amount != 0) {
                $result[$currency] = $round($currency, $amount);
            }
        }

        return $result;
    }
}
