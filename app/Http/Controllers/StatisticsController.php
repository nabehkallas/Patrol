<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\FuelType;
use App\Models\Transaction;
use App\Services\AnnualFinancialSummary;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use App\Support\Currency;
use App\Support\ExportTable;
use App\Support\Locales;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    use GroupsByCurrency;

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

        // Money figures stay in the currency they were recorded in: each is a per-currency
        // breakdown, never converted (exchange rates are only used by currency exchanges).

        $transactions = $this->filteredTransactions($request, $from, $to);

        $byFuelType = $transactions
            ->where('type', TransactionType::FuelSale)
            ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
            ->map(function (Collection $txns) {
                $name = $txns->first()->fuelType->name ?? '—';

                return [
                    'name' => $name,
                    'liters' => round(
                        $txns->where('is_governmental', false)->sum(fn (Transaction $t) => (float) $t->liters),
                        3
                    ),
                    'income' => $this->byCurrency($txns->reject(fn (Transaction $t) => $t->isPendingDebt())),
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
            'totals' => $this->summarize($transactions),
            'byFuelType' => $byFuelType,
            'salesChart' => $salesChart,
            'deliveriesByFuelType' => $transactions
                ->where('type', TransactionType::FuelDelivery)
                ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
                ->map(fn (Collection $txns) => [
                    'name' => $txns->first()->fuelType->name ?? '—',
                    'liters' => round($txns->sum(fn (Transaction $t) => (float) $t->liters), 3),
                    'cost' => $this->byCurrency($txns),
                ])
                ->sortBy('name')
                ->values(),
            'debtPosition' => $this->debtPosition(),
            'shopSales' => $this->shopSales($transactions),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'tab' => 'overview',
            'annualUnlocked' => $annualUnlocked,
        ];

        if ($isAdmin) {
            $props['byUser'] = $transactions
                ->groupBy('user_id')
                ->map(function (Collection $txns) {
                    $u = $txns->first()->user;

                    return [
                        'user' => ['id' => $u?->id, 'name' => $u->name ?? '—'],
                        'totals' => $this->summarize($txns),
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
     * @return array{total: array<string, float>, items: array<int, array<string, mixed>>}
     */
    private function shopSales(Collection $transactions): array
    {
        $sales = $transactions
            ->where('type', TransactionType::OtherIncome)
            ->whereNotNull('shop_item_id')
            ->reject(fn (Transaction $t) => $t->isPendingDebt());

        return [
            'total' => $this->byCurrency($sales),
            'items' => $sales
                ->groupBy('shop_item_id')
                ->map(fn (Collection $txns) => [
                    'name' => $txns->first()->shopItem->name ?? '—',
                    'quantity' => (int) $txns->sum('quantity'),
                    'revenue' => $this->byCurrency($txns),
                ])
                // Best sellers by their primary-currency revenue, then by any other currency.
                ->sortByDesc(fn (array $item) => [$item['revenue'][Currency::primary()] ?? 0, array_sum($item['revenue'])])
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        return $this->exportTable($request)->pdf($exporter);
    }

    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        return $this->exportTable($request)->xlsx($exporter);
    }

    /**
     * The period's figures as one table: fuel sold and income by fuel type and by employee (in
     * the primary currency), then deliveries and debt activity in their own currencies.
     */
    private function exportTable(Request $request): ExportTable
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        // Money figures stay in the currency they were recorded in: each is a per-currency
        // breakdown, never converted (exchange rates are only used by currency exchanges).

        $transactions = $this->filteredTransactions($request, $from, $to);

        $labels = Locales::labels([
            'title' => 'Statistics',
            'section' => 'Section',
            'name' => 'Name',
            'liters' => 'Liters',
            'amount' => 'Amount',
            'currency' => 'common.currency',
            'by_fuel_type' => 'By fuel type',
            'by_employee' => 'By employee',
            'deliveries' => 'Fuel deliveries',
            'debts_created' => 'New debts',
            'debts_settled' => 'Debts settled',
            'receivable' => 'Owed to us',
            'payable' => 'We owe',
        ]);

        $rows = $transactions
            ->where('type', TransactionType::FuelSale)
            ->groupBy(fn (Transaction $t) => $t->fuel_type_id ?? 0)
            ->flatMap(function (Collection $txns) use ($labels) {
                $name = $txns->first()->fuelType->name ?? '—';
                $liters = (float) $txns->where('is_governmental', false)->sum(fn (Transaction $t) => (float) $t->liters);

                return collect($this->byCurrency($txns->reject(fn (Transaction $t) => $t->isPendingDebt())))
                    ->map(fn (float $amount, string $currency) => [$labels['by_fuel_type'], $name, $liters, $amount, $currency])
                    ->values();
            })
            ->values()
            ->all();

        if ($isAdmin) {
            $employeeRows = $transactions
                ->groupBy('user_id')
                ->flatMap(function (Collection $txns) use ($labels) {
                    $summary = $this->summarize($txns);
                    $name = $txns->first()->user->name ?? '—';

                    return collect($summary['income'])
                        ->map(fn (float $amount, string $currency) => [$labels['by_employee'], $name, (float) $summary['liters_sold'], $amount, $currency])
                        ->values();
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
                (float) $t->liters,
                (float) $t->amount,
                $t->currency,
            ])
            ->values()
            ->all();

        [$debtsCreated, $debtsSettled] = $this->debtActivity($request, $from, $to);

        $debtRows = [
            ...collect($debtsCreated)->map(fn (array $debt) => [
                $labels['debts_created'],
                $debt['debtor_name'].' ('.($debt['direction'] === 'payable' ? $labels['payable'] : $labels['receivable']).')',
                null,
                (float) $debt['amount'],
                $debt['currency'],
            ])->all(),
            ...collect($debtsSettled)->map(fn (array $payment) => [
                $labels['debts_settled'],
                $payment['debtor_name'].' ('.($payment['direction'] === 'payable' ? $labels['payable'] : $labels['receivable']).')',
                null,
                (float) $payment['amount'],
                $payment['currency'],
            ])->all(),
        ];

        $rows = [...$rows, ...$deliveryRows, ...$debtRows];

        return new ExportTable(
            name: 'statistics',
            title: $labels['title'],
            subtitle: $from->toDateString().' – '.$to->toDateString(),
            headers: [$labels['section'], $labels['name'], $labels['liters'], $labels['amount'], $labels['currency']],
            rows: $rows,
            decimals: [2 => 3, 3 => fn (array $row) => Currency::decimals((string) $row[4])],
        );
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{income: array<string, float>, expense: array<string, float>, sadcop: array<string, float>, liters_sold: float, liters_delivered: float}
     */
    private function summarize(Collection $transactions): array
    {
        $income = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $t) => $t->isPendingDebt());

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
            'income' => $this->byCurrency($income),
            'expense' => $this->byCurrency($moneyOut->reject($isSadcopPayment)),
            'sadcop' => $this->byCurrency($moneyOut->filter($isSadcopPayment)),
            'liters_sold' => round($litersSold, 3),
            'liters_delivered' => round($litersDelivered, 3),
        ];
    }
}
