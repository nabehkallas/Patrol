<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\DebtPayment;
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

        // Defaults to today — this page is primarily a daily report; the date pickers still
        // allow widening it into a longer-range summary when that's what's needed instead.
        $from = $request->date('from') ?? now()->startOfDay();
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

        [$debtsCreated, $debtsSettled] = $this->debtActivity($request, $from, $to);

        $props = [
            'totals' => $this->summarize($transactions, $sypRate),
            'byFuelType' => $byFuelType,
            'salesChart' => $salesChart,
            'transactions' => $transactions
                ->sortByDesc('occurred_at')
                ->values()
                ->map(fn (Transaction $t) => $this->transactionRow($t))
                ->all(),
            'deliveries' => $transactions
                ->where('type', TransactionType::FuelDelivery)
                ->sortByDesc('occurred_at')
                ->values()
                ->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'occurred_at' => $t->occurred_at->toIso8601String(),
                    'tank_name' => $t->tank?->name,
                    'fuel_type_name' => $t->fuelType?->name,
                    'liters' => (float) $t->liters,
                    'price_per_liter' => $t->price_per_liter !== null ? (float) $t->price_per_liter : null,
                    'amount' => (float) $t->amount,
                    'currency' => $t->currency->value,
                    'paid_by_sadcop' => $t->paid_by_sadcop,
                ])
                ->all(),
            'debtsCreated' => $debtsCreated,
            'debtsSettled' => $debtsSettled,
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

    private function transactionRow(Transaction $t): array
    {
        $tankRelevant = in_array($t->type, [TransactionType::FuelSale, TransactionType::FuelDelivery], true);

        return [
            'id' => $t->id,
            'type' => $t->type->value,
            'description' => $this->describeTransaction($t),
            'tank_name' => $tankRelevant ? $t->tank?->name : null,
            'liters' => $t->liters !== null ? (float) $t->liters : null,
            'amount' => (float) $t->amount,
            'currency' => $t->currency->value,
            'occurred_at' => $t->occurred_at->toIso8601String(),
            'is_governmental' => $t->is_governmental,
            'is_pending_debt' => $t->isPendingDebt(),
        ];
    }

    /**
     * Mirrors CashBoxController::describeTransaction() — a currency exchange has no fuel type
     * or description of its own, so it needs its own "X → Y" summary instead.
     */
    private function describeTransaction(Transaction $t): string
    {
        if ($t->type === TransactionType::CurrencyExchange) {
            return number_format((float) $t->amount, 2).' '.$t->currency->value
                .' → '.number_format((float) $t->to_amount, 2).' '.$t->to_currency->value;
        }

        return $t->fuelType?->name ?? $t->description ?? $t->type->value;
    }

    private function filteredTransactions(Request $request, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        return Transaction::query()
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->with(['user', 'fuelType', 'tank', 'debt'])
            ->get();
    }

    /**
     * Debts touched within the range — newly created ones, and payments (partial or full)
     * made against any debt, regardless of when that debt was originally recorded.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function debtActivity(Request $request, CarbonInterface $from, CarbonInterface $to): array
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $created = Debt::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $user->id))
            ->with('debtor')
            ->get()
            ->map(fn (Debt $debt) => [
                'id' => $debt->id,
                'debtor_name' => $debt->debtor?->name ?? '—',
                'direction' => $debt->direction->value,
                'amount' => (float) $debt->amount,
                'currency' => $debt->currency->value,
                'date' => $debt->date->toDateString(),
            ])
            ->all();

        $settled = DebtPayment::query()
            ->where('paid_at', '>=', $from->copy()->startOfDay())
            ->where('paid_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $user->id))
            ->with('debt.debtor')
            ->get()
            ->map(fn (DebtPayment $payment) => [
                'id' => $payment->id,
                'debtor_name' => $payment->debt?->debtor?->name ?? '—',
                'direction' => $payment->debt?->direction->value,
                'amount' => (float) $payment->amount,
                'currency' => $payment->debt?->currency->value,
                'paid_at' => $payment->paid_at->toIso8601String(),
            ])
            ->all();

        return [$created, $settled];
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $from = $request->date('from') ?? now()->startOfDay();
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
            'deliveries' => 'توريدات الوقود',
            'debts_created' => 'ديون جديدة',
            'debts_settled' => 'ديون مسددة',
            'receivable' => 'لنا',
            'payable' => 'علينا',
        ] : [
            'title' => 'Statistics',
            'section' => 'Section',
            'name' => 'Name',
            'liters' => 'Liters',
            'income' => 'Income (SYP)',
            'by_fuel_type' => 'By fuel type',
            'by_employee' => 'By employee',
            'deliveries' => 'Fuel deliveries',
            'debts_created' => 'New debts',
            'debts_settled' => 'Debts settled',
            'receivable' => 'Owed to us',
            'payable' => 'We owe',
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

        $deliveryRows = $transactions
            ->where('type', TransactionType::FuelDelivery)
            ->map(fn (Transaction $t) => [
                $labels['deliveries'],
                ($t->fuelType?->name ?? '—').' — '.($t->tank?->name ?? '—'),
                number_format((float) $t->liters, 3),
                number_format((float) $t->amount, 0).' '.$t->currency->value,
            ])
            ->values()
            ->all();

        [$debtsCreated, $debtsSettled] = $this->debtActivity($request, $from, $to);

        $debtRows = [
            ...collect($debtsCreated)->map(fn (array $debt) => [
                $labels['debts_created'],
                $debt['debtor_name'].' ('.($debt['direction'] === 'payable' ? $labels['payable'] : $labels['receivable']).')',
                '—',
                number_format($debt['amount'], 0).' '.$debt['currency'],
            ])->all(),
            ...collect($debtsSettled)->map(fn (array $payment) => [
                $labels['debts_settled'],
                $payment['debtor_name'].' ('.($payment['direction'] === 'payable' ? $labels['payable'] : $labels['receivable']).')',
                '—',
                number_format($payment['amount'], 0).' '.$payment['currency'],
            ])->all(),
        ];

        $rows = [...$rows, ...$deliveryRows, ...$debtRows];

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
