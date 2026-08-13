<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Collection;

trait GroupsByCurrency
{
    /**
     * Groups raw (unconverted) amounts by their actual recorded currency — money totals should
     * show what's physically in each currency, not everything blended into one converted
     * figure. Always includes SYP (even if zero, for a consistent primary figure); other
     * currencies are included only when non-zero.
     *
     * @param  Collection<int, object{currency: mixed, amount: mixed}>  $items
     * @param  string|\Closure  $amount  Attribute name (default 'amount') or a per-item resolver —
     *                                   e.g. a debt's remaining balance instead of its full amount.
     * @return array<string, float>
     */
    protected function byCurrency(Collection $items, string|\Closure $amount = 'amount'): array
    {
        $totals = $items
            ->groupBy(fn ($item) => $item->currency->value)
            ->map(fn ($group) => (float) $group->sum($amount))
            ->all();

        $round = fn (string $currency, float $amount) => round($amount, $currency === 'SYP' ? 0 : 2);

        $result = ['SYP' => $round('SYP', $totals['SYP'] ?? 0.0)];

        foreach ($totals as $currency => $amount) {
            if ($currency !== 'SYP' && $amount != 0) {
                $result[$currency] = $round($currency, $amount);
            }
        }

        return $result;
    }
}
