<?php

namespace App\Http\Controllers;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\ExchangeRate;
use App\Models\FuelType;
use App\Models\Transaction;
use App\Services\AnnualFinancialSummary;
use App\Services\PdfTableExporter;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function index(Request $request, AnnualFinancialSummary $annual): Response
    {
        $annualUnlocked = (bool) $request->session()->get(AnnualSummaryAccessController::SESSION_KEY);

        // The protected annual tab renders on its own: it needs none of the overview's
        // date-range figures, and nothing of it is computed or sent until it is unlocked.
        if ($request->query('tab') === 'annual') {
            $years = $annual->availableYears();
            $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : $years[0];

            return Inertia::render('statistics/index', [
                'tab' => 'annual',
                'annualUnlocked' => $annualUnlocked,
                'annual' => $annualUnlocked ? [...$annual->forYear($year), 'years' => $years] : null,
            ]);
        }

        $user = $request->user();
        $isAdmin = $user->isAdmin();

        // 1st of the current month through today -- the same default every other date-range
        // report in the app uses, so a fresh visit here isn't the one place that opens narrower
        // than everywhere else. The pickers still allow narrowing back down to a single day.
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        // Every figure on Statistics is converted into the station's primary currency (the
        // *_syp prop names predate configurable currencies; the values are in the primary one).
        $sypRate = ExchangeRate::currentRateFor(Currency::primary());

        $transactions = $this->filteredTransactions($request, $from, $to);

        $byFuelType = $transactions
            ->where('type', TransactionType::FuelSale)
            ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
            ->map(function (Collection $txns) use ($sypRate) {
                $name = $txns->first()->fuelType->name ?? '—';

                return [
                    'name' => $name,
                    'liters' => round(
                        $txns->where('is_governmental', false)->sum(fn (Transaction $t) => (float) $t->liters),
                        3
                    ),
                    'income_syp' => round(
                        $txns->reject(fn (Transaction $t) => $t->isPendingDebt())->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)),
                        Currency::decimals(Currency::primary())
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
            'deliveriesByFuelType' => $transactions
                ->where('type', TransactionType::FuelDelivery)
                ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
                ->map(fn (Collection $txns) => [
                    'name' => $txns->first()->fuelType->name ?? '—',
                    'liters' => round($txns->sum(fn (Transaction $t) => (float) $t->liters), 3),
                    'cost_syp' => round($txns->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), Currency::decimals(Currency::primary())),
                ])
                ->sortBy('name')
                ->values(),
            'debtPosition' => $this->debtPosition(),
            'shopSales' => $this->shopSales($transactions, $sypRate),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'tab' => 'overview',
            'annualUnlocked' => $annualUnlocked,
        ];

        if ($isAdmin) {
            $props['byUser'] = $transactions
                ->groupBy('user_id')
                ->map(function (Collection $txns) use ($sypRate) {
                    $u = $txns->first()->user;

                    return [
                        'user' => ['id' => $u?->id, 'name' => $u->name ?? '—'],
                        'totals' => $this->summarize($txns, $sypRate),
                    ];
                })
                ->values();
        }

        return Inertia::render('statistics/index', $props);
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function filteredTransactions(Request $request, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        return Transaction::query()
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])
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
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Debt $debt) => [
                'id' => $debt->id,
                'debtor_name' => $debt->debtor->name ?? '—',
                'direction' => $debt->direction->value,
                'amount' => (float) $debt->amount,
                'currency' => $debt->currency,
                'date' => $debt->date->toDateString(),
            ])
            ->all();

        $settled = DebtPayment::query()
            ->where('paid_at', '>=', $from->copy()->startOfDay())
            ->where('paid_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $user->id))
            ->with('debt.debtor')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DebtPayment $payment) => [
                'id' => $payment->id,
                'debtor_name' => $payment->debt?->debtor->name ?? '—',
                'direction' => $payment->debt?->direction->value,
                'amount' => (float) $payment->amount,
                'currency' => $payment->debt?->currency,
                'paid_at' => $payment->paid_at->toIso8601String(),
            ])
            ->all();

        return [$created, $settled];
    }

    /**
     * Outstanding debt balances right now, per currency and direction -- the same remaining-
     * after-payments figures the Debts page's "unpaid" totals show. Station-wide and not tied
     * to the selected date range, since it's a current position rather than activity.
     *
     * @return array{receivable: array<string, float>, payable: array<string, float>}
     */
    private function debtPosition(): array
    {
        $outstanding = Debt::where('status', DebtStatus::Outstanding)->with('payments')->get();

        $byCurrency = fn (DebtDirection $direction) => $outstanding
            ->where('direction', $direction)
            ->groupBy(fn (Debt $debt) => $debt->currency)
            ->map(fn (Collection $debts) => round($debts->sum(fn (Debt $debt) => $debt->remainingAmount()), 2))
            ->all();

        return [
            'receivable' => $byCurrency(DebtDirection::Receivable),
            'payable' => $byCurrency(DebtDirection::Payable),
        ];
    }

    /**
     * Shop sales in the range: their total revenue (already part of the revenue KPI, since a
     * shop sale is recorded as other income) and the best-selling items by revenue.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array{total_syp: float, items: array<int, array<string, mixed>>}
     */
    private function shopSales(Collection $transactions, float $sypRate): array
    {
        $sales = $transactions
            ->where('type', TransactionType::OtherIncome)
            ->whereNotNull('shop_item_id')
            ->reject(fn (Transaction $t) => $t->isPendingDebt());

        return [
            'total_syp' => round($sales->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), Currency::decimals(Currency::primary())),
            'items' => $sales
                ->groupBy('shop_item_id')
                ->map(fn (Collection $txns) => [
                    'name' => $txns->first()->shopItem->name ?? '—',
                    'quantity' => (int) $txns->sum('quantity'),
                    'revenue_syp' => round($txns->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), Currency::decimals(Currency::primary())),
                ])
                ->sortByDesc('revenue_syp')
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        // Every figure on Statistics is converted into the station's primary currency (the
        // *_syp prop names predate configurable currencies; the values are in the primary one).
        $sypRate = ExchangeRate::currentRateFor(Currency::primary());

        $transactions = $this->filteredTransactions($request, $from, $to);

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'الإحصائيات',
            'section' => 'القسم',
            'name' => 'الاسم',
            'liters' => 'اللترات',
            'income' => 'الدخل ('.Currency::primary().')',
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
            'income' => 'Income ('.Currency::primary().')',
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
                    $txns->first()->fuelType->name ?? '—',
                    number_format($txns->where('is_governmental', false)->sum(fn (Transaction $t) => (float) $t->liters), 3),
                    number_format($txns->reject(fn (Transaction $t) => $t->isPendingDebt())->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), Currency::decimals(Currency::primary())),
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
                        $txns->first()->user->name ?? '—',
                        number_format($summary['liters_sold'], 3),
                        number_format($summary['income_syp'], Currency::decimals(Currency::primary())),
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
                ($t->fuelType->name ?? '—').' — '.($t->tank->name ?? '—'),
                number_format((float) $t->liters, 3),
                number_format((float) $t->amount, 0).' '.$t->currency,
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

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, float>
     */
    private function summarize(Collection $transactions, float $sypRate): array
    {
        $incomeSyp = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $t) => $t->isPendingDebt())
            ->sum(fn (Transaction $t) => $t->amountInSyp($sypRate));

        // Money out, split the way Cash Box splits it: a payment into Sadcop's balance is a
        // Purchase linked to a Sadcop ledger entry; everything else is an operating expense.
        $moneyOut = $transactions
            ->whereIn('type', [TransactionType::Expense, TransactionType::Purchase])
            ->reject(fn (Transaction $t) => $t->isPendingDebt());
        $isSadcopPayment = fn (Transaction $t) => $t->sadcopLedgerEntry !== null;

        $litersSold = $transactions
            ->where('type', TransactionType::FuelSale)
            ->where('is_governmental', false)
            ->sum(fn (Transaction $t) => (float) $t->liters);

        $litersDelivered = $transactions
            ->where('type', TransactionType::FuelDelivery)
            ->sum(fn (Transaction $t) => (float) $t->liters);

        return [
            'income_syp' => round($incomeSyp, Currency::decimals(Currency::primary())),
            'expense_syp' => round($moneyOut->reject($isSadcopPayment)->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), Currency::decimals(Currency::primary())),
            'sadcop_syp' => round($moneyOut->filter($isSadcopPayment)->sum(fn (Transaction $t) => $t->amountInSyp($sypRate)), Currency::decimals(Currency::primary())),
            'liters_sold' => round($litersSold, 3),
            'liters_delivered' => round($litersDelivered, 3),
        ];
    }
}
