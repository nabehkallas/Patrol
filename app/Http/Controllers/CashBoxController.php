<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\Currency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CashBoxController extends Controller
{
    use GroupsByCurrency;

    public function index(Request $request): Response
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        return Inertia::render('cash-box/index', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'cashBox' => [
                'period' => $this->summarize($from->copy()->startOfDay(), $to->copy()->endOfDay(), $isAdmin, $user->id, $sypRate),
                'today' => $this->summarize(now()->startOfDay(), now()->endOfDay(), $isAdmin, $user->id, $sypRate),
            ],
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $period = $this->summarize($from->copy()->startOfDay(), $to->copy()->endOfDay(), $isAdmin, $user->id, $sypRate);
        $today = $this->summarize(now()->startOfDay(), now()->endOfDay(), $isAdmin, $user->id, $sypRate);

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'صندوق النقد',
            'metric' => 'المؤشر',
            'value' => 'القيمة',
            'section' => 'الفترة',
            'period' => 'الفترة المحددة',
            'today' => 'اليوم',
            'income' => 'الدخل',
            'sadcop' => 'مدفوعات سادكوب',
            'other_expenses' => 'مصروفات أخرى',
            'exchanged' => 'تحويل عملة',
            'net' => 'الصافي',
            'liters_sold' => 'اللترات المباعة',
            'debts' => 'الديون (غير مسددة)',
            'debts_liters' => 'لترات مباعة بالدين (غير مسددة)',
        ] : [
            'title' => 'Cash Box',
            'metric' => 'Metric',
            'value' => 'Value',
            'section' => 'Section',
            'period' => 'Selected period',
            'today' => 'Today',
            'income' => 'Income',
            'sadcop' => 'Sadcop payments',
            'other_expenses' => 'Other expenses',
            'exchanged' => 'Currency exchanged',
            'net' => 'Net',
            'liters_sold' => 'Liters sold',
            'debts' => 'Debts (unsettled)',
            'debts_liters' => 'Liters sold in debt (unsettled)',
        ];

        $formatBreakdown = fn (array $breakdown) => collect($breakdown)
            ->map(fn ($amount, $currency) => number_format($amount, $currency === 'SYP' ? 0 : 2).' '.$currency)
            ->implode(' + ');

        $rowsFor = fn (array $summary) => [
            [$labels['income'], $formatBreakdown($summary['income'])],
            [$labels['sadcop'], number_format($summary['sadcop_expense_syp'], 0).' SYP'],
            [$labels['other_expenses'], $formatBreakdown($summary['other_expense'])],
            [$labels['exchanged'], $formatBreakdown($summary['exchanged'])],
            [$labels['net'], $formatBreakdown($summary['net'])],
            [$labels['liters_sold'], number_format($summary['liters_sold'], 3).' L'],
            [$labels['debts'], $formatBreakdown($summary['debts'])],
            [$labels['debts_liters'], number_format($summary['debts_liters_sold'], 3).' L'],
        ];

        $rows = [
            [$labels['period'], '', ''],
            ...array_map(fn ($row) => [$labels['period'], ...$row], $rowsFor($period)),
            [$labels['today'], '', ''],
            ...array_map(fn ($row) => [$labels['today'], ...$row], $rowsFor($today)),
        ];

        return $exporter->download(
            filename: 'cash-box-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: [$labels['section'], $labels['metric'], $labels['value']],
            rows: $rows,
            direction: $direction,
        );
    }

    /**
     * Income, expenses (split between Sadcop payments and other expenses), debts, and liters
     * sold for the given range — mirrors the dashboard's cash box totals. Fuel deliveries never
     * touch the attendant's cash register, so they're excluded entirely (they're tracked as
     * inventory movements, not cash flow).
     */
    private function summarize(CarbonInterface $from, CarbonInterface $to, bool $isAdmin, int $userId, float $sypRate): array
    {
        $transactions = Transaction::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $userId))
            ->with(['fuelType', 'debt', 'sadcopLedgerEntry'])
            ->get();

        // Only debts owed *to* the station reflect cash the business is still due — a debt
        // where the station is the debtor (money or fuel it owes someone else) is tracked
        // separately and must not inflate income, liters sold, or the "owed to us" figures.
        $debts = Debt::query()
            ->where('direction', DebtDirection::Receivable)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->with(['fuelType', 'transaction'])
            ->get();

        // The "debts" card reflects money still owed, so it only counts unsettled debts —
        // but liters sold on credit are real fuel movements and stay in $litersSold below
        // regardless of settlement status.
        $outstandingDebts = $debts->where('status', DebtStatus::Outstanding);

        // A debt (of either direction) is the source of truth for *when* its cash actually
        // moves — settled here means paid today, regardless of when the underlying sale or
        // expense was first recorded — so debt-linked transactions never contribute to
        // income/expense themselves; $settledReceivableDebts / $settledPayableDebts below do.
        $incomeTransactions = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $transaction) => $transaction->debt !== null);

        $expenseTransactions = $transactions
            ->where('type', TransactionType::Expense)
            ->reject(fn (Transaction $transaction) => $transaction->debt !== null);

        $isSadcopPayment = fn (Transaction $transaction) => $transaction->sadcopLedgerEntry !== null;

        $sadcopTransactions = $expenseTransactions->filter($isSadcopPayment);
        $otherExpenseTransactions = $expenseTransactions->reject($isSadcopPayment);

        $sadcopExpenseSyp = $sadcopTransactions->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));

        // Debts settled within this window — whether tied to a transaction or standalone —
        // are cash that actually arrived (receivable) or went out (payable) today.
        $settledReceivableDebts = Debt::query()
            ->where('direction', DebtDirection::Receivable)
            ->where('status', DebtStatus::Settled)
            ->where('settled_at', '>=', $from)
            ->where('settled_at', '<=', $to)
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->get();

        $settledPayableDebts = Debt::query()
            ->where('direction', DebtDirection::Payable)
            ->where('status', DebtStatus::Settled)
            ->where('settled_at', '>=', $from)
            ->where('settled_at', '<=', $to)
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->get();

        $incomeBreakdown = $this->byCurrency($incomeTransactions->concat($settledReceivableDebts));
        $otherExpenseBreakdown = $this->byCurrency($otherExpenseTransactions->concat($settledPayableDebts));

        $exchangeTransactions = $transactions->where('type', TransactionType::CurrencyExchange);
        $exchangedBreakdown = $this->exchangedByCurrency($exchangeTransactions);

        $standaloneDebtLiters = $debts->whereNotNull('liters');

        $litersSold = $transactions
            ->where('type', TransactionType::FuelSale)
            ->sum(fn (Transaction $transaction) => (float) $transaction->liters)
            + $standaloneDebtLiters->sum(fn (Debt $debt) => (float) $debt->liters);

        // Liters behind an unsettled debt (governmental sales, or any other fuel sold on
        // credit), whether recorded via a linked transaction or a standalone liters-based debt.
        $litersSoldInDebt = $outstandingDebts
            ->sum(fn (Debt $debt) => (float) ($debt->liters ?? $debt->transaction?->liters ?? 0));

        return [
            'income' => $incomeBreakdown,
            'sadcop_expense_syp' => round($sadcopExpenseSyp, 0),
            'other_expense' => $otherExpenseBreakdown,
            'exchanged' => $exchangedBreakdown,
            'net' => $this->netByCurrency($incomeBreakdown, $otherExpenseBreakdown, $sadcopExpenseSyp, $exchangedBreakdown),
            'liters_sold' => round($litersSold, 3),
            'debts' => $this->byCurrency($outstandingDebts),
            'debts_liters_sold' => round($litersSoldInDebt, 3),
        ];
    }

    /**
     * Net effect of currency-exchange transactions on cash held in each currency: negative
     * for the currency given up, positive for the currency received. Unlike income/expense
     * breakdowns this is signed and may be negative per currency (an exchange doesn't create
     * or destroy value, it just moves it between currencies).
     *
     * @param  Collection<int, Transaction>  $exchanges
     * @return array<string, float>
     */
    private function exchangedByCurrency($exchanges): array
    {
        $totals = [];

        foreach ($exchanges as $transaction) {
            $from = $transaction->currency->value;
            $to = $transaction->to_currency->value;

            $totals[$from] = ($totals[$from] ?? 0.0) - (float) $transaction->amount;
            $totals[$to] = ($totals[$to] ?? 0.0) + (float) $transaction->to_amount;
        }

        $result = [];

        foreach ($totals as $currency => $amount) {
            $rounded = round($amount, $currency === 'SYP' ? 0 : 2);

            if ($currency === 'SYP' || $rounded != 0) {
                $result[$currency] = $rounded;
            }
        }

        return $result;
    }

    /**
     * Income minus expenses plus the net effect of currency exchanges, per currency — sadcop
     * expenses are always SYP by construction (see SadcopController), so they only ever
     * reduce the SYP side.
     *
     * @param  array<string, float>  $income
     * @param  array<string, float>  $otherExpense
     * @param  array<string, float>  $exchanged
     */
    private function netByCurrency(array $income, array $otherExpense, float $sadcopExpenseSyp, array $exchanged): array
    {
        $currencies = array_unique([...array_keys($income), ...array_keys($otherExpense), ...array_keys($exchanged), 'SYP']);

        $result = [];

        foreach ($currencies as $currency) {
            $value = ($income[$currency] ?? 0.0)
                - ($otherExpense[$currency] ?? 0.0)
                + ($exchanged[$currency] ?? 0.0)
                - ($currency === 'SYP' ? $sadcopExpenseSyp : 0.0);

            $rounded = round($value, $currency === 'SYP' ? 0 : 2);

            if ($currency === 'SYP' || $rounded != 0) {
                $result[$currency] = $rounded;
            }
        }

        return $result;
    }
}
