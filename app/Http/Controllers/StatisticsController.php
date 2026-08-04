<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\ExchangeRate;
use App\Models\FuelType;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        $transactions = $this->filteredTransactions($request, $from, $to);

        $byFuelType = $transactions
            ->where('type', TransactionType::FuelSale)
            ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
            ->map(function (Collection $txns) use ($sypRate) {
                $name = $txns->first()->fuelType?->name ?? '—';

                return [
                    'name' => $name,
                    'liters' => round(
                        $txns->where('is_governmental', false)->sum(fn (Transaction $t) => (float) $t->liters),
                        3
                    ),
                    'income_syp' => round(
                        $txns->reject(fn (Transaction $t) => $t->isPendingDebt())->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)),
                        0
                    ),
                ];
            })
            ->sortBy('name')
            ->values();

        $fuelTypeNames = FuelType::orderBy('name')->pluck('name')->all();

        $dailyData = [];
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->startOfDay()->addDays($i)->format('Y-m-d');
            $dailyData[$date] = array_fill_keys($fuelTypeNames, 0.0);
        }

        foreach ($transactions->where('type', TransactionType::FuelSale)->where('is_governmental', false) as $sale) {
            $date = $sale->occurred_at->format('Y-m-d');
            $name = $sale->fuelType?->name;
            if ($name && isset($dailyData[$date][$name])) {
                $dailyData[$date][$name] += (float) $sale->liters;
            }
        }

        $salesChart = [
            'fuelTypes' => $fuelTypeNames,
            'data' => collect($dailyData)
                ->map(fn (array $values, string $date) => array_merge(
                    ['date' => $date],
                    array_map(fn ($v) => round($v, 3), $values)
                ))
                ->values()
                ->all(),
        ];

        $props = [
            'totals' => $this->summarize($transactions, $sypRate),
            'byFuelType' => $byFuelType,
            'salesChart' => $salesChart,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        if ($isAdmin) {
            $props['byUser'] = $transactions
                ->groupBy('user_id')
                ->map(function (Collection $txns) use ($sypRate) {
                    $u = $txns->first()->user;

                    return [
                        'user' => ['id' => $u?->id, 'name' => $u?->name ?? '—'],
                        'totals' => $this->summarize($txns, $sypRate),
                    ];
                })
                ->values();
        }

        return Inertia::render('statistics/index', $props);
    }

    private function filteredTransactions(Request $request, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        return Transaction::query()
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->with(['user', 'fuelType', 'debt'])
            ->get();
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        $transactions = $this->filteredTransactions($request, $from, $to);

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'الإحصائيات',
            'section' => 'القسم',
            'name' => 'الاسم',
            'liters' => 'اللترات',
            'income' => 'الدخل (ل.س)',
            'by_fuel_type' => 'حسب نوع الوقود',
            'by_employee' => 'حسب الموظف',
        ] : [
            'title' => 'Statistics',
            'section' => 'Section',
            'name' => 'Name',
            'liters' => 'Liters',
            'income' => 'Income (SYP)',
            'by_fuel_type' => 'By fuel type',
            'by_employee' => 'By employee',
        ];

        $rows = $transactions
            ->where('type', TransactionType::FuelSale)
            ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
            ->map(function (Collection $txns) use ($sypRate, $labels) {
                return [
                    $labels['by_fuel_type'],
                    $txns->first()->fuelType?->name ?? '—',
                    number_format($txns->where('is_governmental', false)->sum(fn (Transaction $t) => (float) $t->liters), 3),
                    number_format($txns->reject(fn (Transaction $t) => $t->isPendingDebt())->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), 0),
                ];
            })
            ->values()
            ->all();

        if ($isAdmin) {
            $employeeRows = $transactions
                ->groupBy('user_id')
                ->map(function (Collection $txns) use ($sypRate, $labels) {
                    $summary = $this->summarize($txns, $sypRate);

                    return [
                        $labels['by_employee'],
                        $txns->first()->user?->name ?? '—',
                        number_format($summary['liters_sold'], 3),
                        number_format($summary['income_syp'], 0),
                    ];
                })
                ->values()
                ->all();

            $rows = [...$rows, ...$employeeRows];
        }

        return $exporter->download(
            filename: 'statistics-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: [$labels['section'], $labels['name'], $labels['liters'], $labels['income']],
            rows: $rows,
            direction: $direction,
        );
    }

    private function summarize(Collection $transactions, float $sypRate): array
    {
        $incomeSyp = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $t) => $t->isPendingDebt())
            ->sum(fn (Transaction $t) => $t->amountInSyp($sypRate));

        $expenseSyp = $transactions
            ->where('type', TransactionType::Expense)
            ->reject(fn (Transaction $t) => $t->isPendingDebt())
            ->sum(fn (Transaction $t) => $t->amountInSyp($sypRate));

        $litersSold = $transactions
            ->where('type', TransactionType::FuelSale)
            ->where('is_governmental', false)
            ->sum(fn (Transaction $t) => (float) $t->liters);

        $litersDelivered = $transactions
            ->where('type', TransactionType::FuelDelivery)
            ->sum(fn (Transaction $t) => (float) $t->liters);

        return [
            'income_syp' => round($incomeSyp, 0),
            'expense_syp' => round($expenseSyp, 0),
            'net_syp' => round($incomeSyp - $expenseSyp, 0),
            'liters_sold' => round($litersSold, 3),
            'liters_delivered' => round($litersDelivered, 3),
        ];
    }
}
