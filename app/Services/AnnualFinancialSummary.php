<?php

namespace App\Services;

use App\Enums\TransactionType;
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
 * Each currency has its own months and totals (no conversion): amounts stay in the currency
 * they were recorded in, like the rest of Statistics.
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
        $primary = Currency::primary();

        $transactions = Transaction::query()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<=', $start->endOfYear())
            ->with(['fuelType', 'debt', 'sadcopLedgerEntry'])
            ->get();

        $emptyYear = function (): array {
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

            return $months;
        };

        // Money per currency, each in the currency it was recorded in; liters are the same for
        // every currency's table.
        $byCurrency = [$primary => $emptyYear()];
        $liters = array_fill(1, 12, 0.0);
        $litersByFuelType = [];

        foreach ($transactions as $t) {
            $month = $t->occurred_at->month;
            $pending = $t->isPendingDebt();
            $amount = (float) $t->amount;
            $byCurrency[$t->currency] ??= $emptyYear();
            $row = &$byCurrency[$t->currency][$month];

            switch ($t->type) {
                case TransactionType::FuelSale:
                    if (! $t->is_governmental) {
                        $liters[$month] += (float) $t->liters;
                        $name = $t->fuelType->name ?? '—';
                        $litersByFuelType[$name] = ($litersByFuelType[$name] ?? 0.0) + (float) $t->liters;
                    }
                    if (! $pending) {
                        $row['fuel_revenue'] += $amount;
                    }
                    break;

                case TransactionType::OtherIncome:
                    if (! $pending) {
                        $row[$t->shop_item_id ? 'store_revenue' : 'other_revenue'] += $amount;
                    }
                    break;

                case TransactionType::Expense:
                case TransactionType::Purchase:
                    if (! $pending) {
                        $row[$t->sadcopLedgerEntry !== null ? 'sadcop' : 'expenses'] += $amount;
                    }
                    break;

                default:
                    break;
            }

            unset($row);
        }

        $currencies = [];
        foreach ($byCurrency as $currency => $months) {
            $summary = $this->summarizeCurrency((string) $currency, $months, $liters);

            // The primary currency always has its table; another only when it had any money.
            if ($currency === $primary || $summary['has_money']) {
                unset($summary['has_money']);
                $currencies[$currency] = $summary;
            }
        }

        ksort($litersByFuelType);
        $totalLiters = array_sum($litersByFuelType);

        return [
            'year' => $year,
            // The primary currency first, then the others.
            'currencies' => [$primary => $currencies[$primary]] + $currencies,
            'liters_by_fuel_type' => collect($litersByFuelType)
                ->map(fn (float $l, string $name) => [
                    'name' => $name,
                    'liters' => round($l, 3),
                    'percent' => $totalLiters > 0 ? round($l / $totalLiters * 100, 1) : 0.0,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One currency's year: its months, totals and peak months, with every amount in that
     * currency only.
     *
     * @param  array<int, array<string, mixed>>  $months
     * @param  array<int, float>  $liters
     * @return array<string, mixed>
     */
    private function summarizeCurrency(string $currency, array $months, array $liters): array
    {
        $decimals = Currency::decimals($currency);

        // Round each displayed cell once, then build revenue and the yearly totals from the
        // rounded cells, so every row and the totals row add up exactly on screen.
        $months = array_values(array_map(function (array $row) use ($decimals, $liters): array {
            foreach (['fuel_revenue', 'store_revenue', 'other_revenue', 'expenses', 'sadcop'] as $key) {
                $row[$key] = round($row[$key], $decimals);
            }
            $row['liters'] = round($liters[$row['month']], 3);
            $row['revenue'] = $row['fuel_revenue'] + $row['store_revenue'] + $row['other_revenue'];

            return $row;
        }, $months));

        $totals = ['month' => 0];
        foreach (['liters', 'fuel_revenue', 'store_revenue', 'other_revenue', 'revenue', 'expenses', 'sadcop'] as $key) {
            $totals[$key] = round(array_sum(array_column($months, $key)), $key === 'liters' ? 3 : $decimals);
        }

        $peak = function (string $key) use ($months): ?array {
            $best = collect($months)->sortByDesc($key)->first();

            return $best && $best[$key] > 0 ? ['month' => $best['month'], 'value' => $best[$key]] : null;
        };

        return [
            'months' => $months,
            'totals' => $totals,
            'peak_revenue' => $peak('revenue'),
            'peak_expense' => $peak('expenses'),
            // Show the "other income" column only when the year has any, so the table's revenue
            // columns always add up to the revenue in the chart.
            'has_other_revenue' => $totals['other_revenue'] > 0,
            'has_money' => ($totals['revenue'] + $totals['expenses'] + $totals['sadcop']) != 0,
        ];
    }
}
