<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Support\Currency;
use Carbon\CarbonImmutable;

/**
 * The protected "annual financial summary" on the Statistics page: one row per calendar month
 * of the chosen year, station-wide. Every figure uses the same definitions as the Statistics
 * KPIs (see StatisticsController::summarize()), so a month here matches that month picked in
 * the date range there:
 *  - revenue = fuel sales + other income (store sales and any other income), minus sales still
 *    unpaid on a debt;
 *  - operational expenses = expenses and purchases, except payments into Sadcop's balance;
 *  - Sadcop payments = purchases linked to a Sadcop ledger entry;
 *  - liters sold = non-governmental fuel sales.
 * Amounts are converted to the station's primary currency at the current exchange rate, like
 * the rest of Statistics.
 */
class AnnualFinancialSummary
{
    /**
     * Station years that have any transaction, newest first, always including this year.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $current = now()->year;
        $first = Transaction::min('occurred_at');
        $firstYear = $first ? min((int) substr((string) $first, 0, 4), $current) : $current;

        return range($current, $firstYear);
    }

    /**
     * @return array<string, mixed>
     */
    public function forYear(int $year): array
    {
        $start = CarbonImmutable::create($year)->startOfYear();
        // Converted into the station's primary currency.
        $sypRate = ExchangeRate::currentRateFor(Currency::primary());

        $transactions = Transaction::query()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $start->endOfYear())
            ->with(['fuelType', 'debt', 'sadcopLedgerEntry'])
            ->get();

        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'liters' => 0.0,
                'fuel_revenue' => 0.0,
                'store_revenue' => 0.0,
                'other_revenue' => 0.0,
                'revenue' => 0.0,
                'expenses' => 0.0,
                'sadcop' => 0.0,
            ];
        }

        $litersByFuelType = [];

        foreach ($transactions as $t) {
            $row = &$months[$t->occurred_at->month];
            $pending = $t->isPendingDebt();

            switch ($t->type) {
                case TransactionType::FuelSale:
                    if (! $t->is_governmental) {
                        $row['liters'] += (float) $t->liters;
                        $name = $t->fuelType->name ?? '—';
                        $litersByFuelType[$name] = ($litersByFuelType[$name] ?? 0.0) + (float) $t->liters;
                    }
                    if (! $pending) {
                        $row['fuel_revenue'] += $t->amountInSyp($sypRate, Currency::primary());
                    }
                    break;

                case TransactionType::OtherIncome:
                    if (! $pending) {
                        $row[$t->shop_item_id ? 'store_revenue' : 'other_revenue'] += $t->amountInSyp($sypRate, Currency::primary());
                    }
                    break;

                case TransactionType::Expense:
                case TransactionType::Purchase:
                    if (! $pending) {
                        $row[$t->sadcopLedgerEntry !== null ? 'sadcop' : 'expenses'] += $t->amountInSyp($sypRate, Currency::primary());
                    }
                    break;

                default:
                    break;
            }

            unset($row);
        }

        // Round each displayed cell once, then build revenue and the yearly totals from the
        // rounded cells, so every row and the totals row add up exactly on screen.
        $months = array_values(array_map(function (array $row): array {
            foreach (['fuel_revenue', 'store_revenue', 'other_revenue', 'expenses', 'sadcop'] as $key) {
                $row[$key] = round($row[$key], Currency::decimals(Currency::primary()));
            }
            $row['liters'] = round($row['liters'], 3);
            $row['revenue'] = $row['fuel_revenue'] + $row['store_revenue'] + $row['other_revenue'];

            return $row;
        }, $months));

        $totals = ['month' => 0];
        foreach (['liters', 'fuel_revenue', 'store_revenue', 'other_revenue', 'revenue', 'expenses', 'sadcop'] as $key) {
            $totals[$key] = round(array_sum(array_column($months, $key)), $key === 'liters' ? 3 : Currency::decimals(Currency::primary()));
        }

        $peak = function (string $key) use ($months): ?array {
            $best = collect($months)->sortByDesc($key)->first();

            return $best && $best[$key] > 0 ? ['month' => $best['month'], 'value' => $best[$key]] : null;
        };

        ksort($litersByFuelType);
        $totalLiters = array_sum($litersByFuelType);

        return [
            'year' => $year,
            'months' => $months,
            'totals' => $totals,
            'peak_revenue' => $peak('revenue'),
            'peak_expense' => $peak('expenses'),
            'liters_by_fuel_type' => collect($litersByFuelType)
                ->map(fn (float $liters, string $name) => [
                    'name' => $name,
                    'liters' => round($liters, 3),
                    'percent' => $totalLiters > 0 ? round($liters / $totalLiters * 100, 1) : 0.0,
                ])
                ->values()
                ->all(),
            // Show the "other income" column only when the year has any, so the table's revenue
            // columns always add up to the revenue in the chart.
            'has_other_revenue' => $totals['other_revenue'] > 0,
        ];
    }
}
