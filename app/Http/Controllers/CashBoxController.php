<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\Currency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\DebtPayment;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
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
            'history' => $this->historyEntries($from->copy()->startOfDay(), $to->copy()->endOfDay(), $isAdmin, $user->id),
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
     * One row per day: the running SYP cash balance, that day's SYP sales, Sadcop payments,
     * government ("smart card") debt collections, the running USD cash balance, and SYP/USD
     * expenses. Mirrors the hand-kept daily ledger sheet, not the flat metric/value summary the
     * PDF export uses.
     *
     * Both balance columns are real Excel formulas, carried forward from the *previous* row's
     * complete activity (same pattern as the Sadcop export's المدور column) — a day's own sales/
     * payments/expenses appear in its own row but only affect the *next* row's balance. Unlike
     * Sadcop, this app has no stored "opening cash balance" concept for Cash Box at all (it only
     * ever computes income/expense totals for a range, never an absolute balance), so the first
     * row's balance cells are left blank for the actual starting cash amount to be typed in by
     * hand — exactly like starting a fresh ledger sheet.
     *
     * "Government" ("بطاقة ذكية") is broken out from ordinary SYP sales because it isn't cash in
     * hand the same day — it's a debt payment received later from the government debtor — while
     * regular customer debt payments stay folded into ordinary sales, same as the dashboard's
     * Cash Box totals treat them.
     */
    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $transactions = Transaction::query()
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))
            ->with(['debt', 'sadcopLedgerEntry'])
            ->get();

        $incomeTransactions = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $t) => $t->debt !== null);

        $expenseTransactions = $transactions
            ->whereIn('type', [TransactionType::Expense, TransactionType::Purchase])
            ->reject(fn (Transaction $t) => $t->debt !== null);

        $isSadcopPayment = fn (Transaction $t) => $t->sadcopLedgerEntry !== null;
        $sadcopTransactions = $expenseTransactions->filter($isSadcopPayment);
        $otherExpenseTransactions = $expenseTransactions->reject($isSadcopPayment);

        $governmentDebtorId = Debtor::government()->id;

        $debtPayments = DebtPayment::query()
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $user->id))
            ->with('debt')
            ->get();

        $receivablePayments = $debtPayments->filter(fn (DebtPayment $p) => $p->debt->direction === DebtDirection::Receivable);
        $payablePayments = $debtPayments->filter(fn (DebtPayment $p) => $p->debt->direction === DebtDirection::Payable);
        $governmentPayments = $receivablePayments->filter(fn (DebtPayment $p) => $p->debt->debtor_id === $governmentDebtorId);
        $customerReceivablePayments = $receivablePayments->reject(fn (DebtPayment $p) => $p->debt->debtor_id === $governmentDebtorId);

        $byDay = fn (Collection $items, string $dateField) => $items->groupBy(fn ($item) => $item->{$dateField}->toDateString());
        $sumSyp = fn (Collection $items, string $currencyPath) => (float) $items->filter(fn ($item) => data_get($item, $currencyPath)->value === 'SYP')->sum('amount');
        $sumUsd = fn (Collection $items, string $currencyPath) => (float) $items->filter(fn ($item) => data_get($item, $currencyPath)->value === 'USD')->sum('amount');

        $incomeByDay = $byDay($incomeTransactions, 'occurred_at');
        $sadcopByDay = $byDay($sadcopTransactions, 'occurred_at');
        $otherExpenseTxByDay = $byDay($otherExpenseTransactions, 'occurred_at');
        $customerReceivableByDay = $byDay($customerReceivablePayments, 'paid_at');
        $governmentByDay = $byDay($governmentPayments, 'paid_at');
        $payableByDay = $byDay($payablePayments, 'paid_at');

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'صندوق النقد',
            'date' => 'التاريخ',
            'cash_syp' => 'الصندوق بالسوري',
            'sold_syp' => 'المباع بالسوري',
            'sadcop' => 'دفعات سادكوب',
            'government' => 'بطاقة ذكية',
            'cash_usd' => 'الصندوق بالدولار',
            'expense_syp' => 'مصروف سوري',
            'expense_usd' => 'مصروف دولار',
            'notes' => 'ملاحظات',
        ] : [
            'title' => 'Cash Box',
            'date' => 'Date',
            'cash_syp' => 'Cash Box (SYP)',
            'sold_syp' => 'Sold (SYP)',
            'sadcop' => 'Sadcop Payments',
            'government' => 'Government',
            'cash_usd' => 'Cash Box (USD)',
            'expense_syp' => 'Expense (SYP)',
            'expense_usd' => 'Expense (USD)',
            'notes' => 'Notes',
        ];

        $headerRow = [
            $labels['date'], $labels['cash_syp'], $labels['sold_syp'], $labels['sadcop'],
            $labels['government'], $labels['cash_usd'], $labels['expense_syp'], $labels['expense_usd'],
            $labels['notes'],
        ];

        $cashSypCol = 'B';
        $soldSypCol = 'C';
        $sadcopCol = 'D';
        $governmentCol = 'E';
        $cashUsdCol = 'F';
        $expenseSypCol = 'G';
        $expenseUsdCol = 'H';

        $rows = [];
        $firstDataRow = 5; // title, subtitle, blank, header, then data

        foreach (CarbonPeriod::create($from, $to) as $i => $day) {
            $dayKey = $day->toDateString();
            $thisRow = $firstDataRow + $i;

            $soldSyp = $sumSyp($incomeByDay->get($dayKey, collect()), 'currency')
                + $sumSyp($customerReceivableByDay->get($dayKey, collect()), 'debt.currency');
            $sadcopSyp = (float) $sadcopByDay->get($dayKey, collect())->sum('amount');
            $governmentSyp = $sumSyp($governmentByDay->get($dayKey, collect()), 'debt.currency');
            $expenseSyp = $sumSyp($otherExpenseTxByDay->get($dayKey, collect()), 'currency')
                + $sumSyp($payableByDay->get($dayKey, collect()), 'debt.currency');
            $expenseUsd = $sumUsd($otherExpenseTxByDay->get($dayKey, collect()), 'currency')
                + $sumUsd($payableByDay->get($dayKey, collect()), 'debt.currency');

            if ($i === 0) {
                $cashSypCell = null;
                $cashUsdCell = null;
            } else {
                $previousRow = $thisRow - 1;
                $cashSypCell = "={$cashSypCol}{$previousRow}+{$soldSypCol}{$previousRow}-{$sadcopCol}{$previousRow}+{$governmentCol}{$previousRow}-{$expenseSypCol}{$previousRow}";
                $cashUsdCell = "={$cashUsdCol}{$previousRow}-{$expenseUsdCol}{$previousRow}";
            }

            $rows[] = [
                $dayKey,
                $cashSypCell,
                $soldSyp > 0 ? round($soldSyp, 0) : null,
                $sadcopSyp > 0 ? round($sadcopSyp, 0) : null,
                $governmentSyp > 0 ? round($governmentSyp, 0) : null,
                $cashUsdCell,
                $expenseSyp > 0 ? round($expenseSyp, 0) : null,
                $expenseUsd > 0 ? round($expenseUsd, 2) : null,
                null,
            ];
        }

        return $exporter->download(
            filename: 'cash-box-'.$from->toDateString().'-to-'.$to->toDateString().'.xlsx',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: $headerRow,
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
            ->with(['fuelType', 'transaction', 'payments'])
            ->get();

        // The "debts" card reflects money still owed, so it only counts unsettled debts —
        // but liters sold on credit are real fuel movements and stay in $litersSold below
        // regardless of settlement status.
        $outstandingDebts = $debts->where('status', DebtStatus::Outstanding);

        // A debt's payments are the source of truth for *when* its cash actually moves — a
        // payment today counts today, regardless of when the underlying sale or expense was
        // first recorded — so debt-linked transactions never contribute to income/expense
        // themselves; $receivablePayments / $payablePayments below do.
        $incomeTransactions = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $transaction) => $transaction->debt !== null);

        // Purchases (Sadcop deposits, Shop restocking) still count as money out for these
        // totals — they only differ from a plain Expense by label/category, not by cash effect.
        $expenseTransactions = $transactions
            ->whereIn('type', [TransactionType::Expense, TransactionType::Purchase])
            ->reject(fn (Transaction $transaction) => $transaction->debt !== null);

        $isSadcopPayment = fn (Transaction $transaction) => $transaction->sadcopLedgerEntry !== null;

        $sadcopTransactions = $expenseTransactions->filter($isSadcopPayment);
        $otherExpenseTransactions = $expenseTransactions->reject($isSadcopPayment);

        $sadcopExpenseSyp = $sadcopTransactions->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));

        // Payments made within this window — whether they fully settle a debt or only chip
        // away at it — are cash that actually arrived (receivable) or went out (payable) today.
        // A debt's own settlement is no longer the source of truth for *when* cash moved: each
        // payment is, since a debt can now be paid off across several partial payments.
        $receivablePayments = $this->debtPaymentsFor(DebtDirection::Receivable, $from, $to, $isAdmin, $userId);
        $payablePayments = $this->debtPaymentsFor(DebtDirection::Payable, $from, $to, $isAdmin, $userId);

        $incomeBreakdown = $this->byCurrency($incomeTransactions->concat($receivablePayments));
        $otherExpenseBreakdown = $this->byCurrency($otherExpenseTransactions->concat($payablePayments));

        $exchangeTransactions = $transactions->where('type', TransactionType::CurrencyExchange);
        $exchangedBreakdown = $this->exchangedByCurrency($exchangeTransactions);

        $standaloneDebtLiters = $debts->whereNotNull('liters');

        $litersSold = $transactions
            ->where('type', TransactionType::FuelSale)
            ->sum(fn (Transaction $transaction) => (float) $transaction->liters)
            + $standaloneDebtLiters->sum(fn (Debt $debt) => (float) $debt->liters);

        $litersSoldByFuelType = $this->litersByFuelType(
            $transactions->where('type', TransactionType::FuelSale),
            $standaloneDebtLiters,
        );

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
            'liters_sold_by_fuel_type' => $litersSoldByFuelType,
            'debts' => $this->byCurrency($outstandingDebts, fn (Debt $debt) => $debt->remainingAmount()),
            'debts_liters_sold' => round($litersSoldInDebt, 3),
        ];
    }

    /**
     * Liters sold per fuel type, combining fuel-sale transactions and standalone liters-based
     * debts (credit/governmental sales that never got a Transaction row) — the same two sources
     * $litersSold above adds together, just split out by fuel type instead of combined.
     *
     * @param  Collection<int, Transaction>  $fuelSaleTransactions
     * @param  Collection<int, Debt>  $standaloneDebtLiters
     * @return array<int, array{name: string, liters: float}>
     */
    private function litersByFuelType(Collection $fuelSaleTransactions, Collection $standaloneDebtLiters): array
    {
        $fromTransactions = $fuelSaleTransactions
            ->filter(fn (Transaction $transaction) => $transaction->fuel_type_id !== null)
            ->groupBy('fuel_type_id')
            ->map(fn ($group) => [
                'name' => $group->first()->fuelType?->name,
                'liters' => (float) $group->sum('liters'),
            ]);

        $fromDebts = $standaloneDebtLiters
            ->filter(fn (Debt $debt) => $debt->fuel_type_id !== null)
            ->groupBy('fuel_type_id')
            ->map(fn ($group) => [
                'name' => $group->first()->fuelType?->name,
                'liters' => (float) $group->sum('liters'),
            ]);

        return $fromTransactions->keys()->concat($fromDebts->keys())->unique()
            ->map(fn ($fuelTypeId) => [
                'name' => $fromTransactions[$fuelTypeId]['name'] ?? $fromDebts[$fuelTypeId]['name'] ?? null,
                'liters' => round(($fromTransactions[$fuelTypeId]['liters'] ?? 0.0) + ($fromDebts[$fuelTypeId]['liters'] ?? 0.0), 3),
            ])
            ->filter(fn (array $row) => $row['name'] !== null)
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Debt payments of the given direction within a window, as plain currency/amount pairs
     * (a payment has no currency of its own — it's always in its parent debt's currency).
     *
     * @return Collection<int, object{currency: Currency, amount: float}>
     */
    private function debtPaymentsFor(DebtDirection $direction, CarbonInterface $from, CarbonInterface $to, bool $isAdmin, int $userId): Collection
    {
        return DebtPayment::query()
            ->whereHas('debt', fn ($q) => $q->where('direction', $direction))
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->with('debt')
            ->get()
            ->map(fn (DebtPayment $payment) => (object) [
                'currency' => $payment->debt->currency,
                'amount' => (float) $payment->amount,
            ]);
    }

    /**
     * A chronological ledger of everything that actually moved cash in the register — every
     * income/expense/exchange transaction plus every debt payment (dated at payment, since
     * that's when the cash actually moved — a debt can now be paid off across several partial
     * payments) — everything the "period" summary above is built from, laid out row by row.
     * Fuel deliveries (including Sadcop's) never touch the cash register, so they're excluded
     * entirely, same as in summarize().
     *
     * @return array<int, array{id: string, date: string, type: string, description: string, amount: float, currency: string}>
     */
    private function historyEntries(CarbonInterface $from, CarbonInterface $to, bool $isAdmin, int $userId): array
    {
        $labels = app()->getLocale() === 'ar' ? [
            'sadcop_transfer' => 'تحويل سادكوب',
            'debt_payment' => 'دفعة على دين',
        ] : [
            'sadcop_transfer' => 'Sadcop transfer',
            'debt_payment' => 'Debt payment',
        ];

        $transactions = Transaction::query()
            ->whereIn('type', [
                TransactionType::FuelSale,
                TransactionType::OtherIncome,
                TransactionType::Expense,
                TransactionType::Purchase,
                TransactionType::CurrencyExchange,
            ])
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $userId))
            ->with(['fuelType', 'debt', 'sadcopLedgerEntry'])
            ->get()
            // A transaction with a debt is represented by that debt's payment entries instead
            // (below) — it hasn't moved cash yet if outstanding, and once paid (in full or in
            // part), the payment date(s) — not this transaction's date — are when cash moved.
            ->reject(fn (Transaction $transaction) => $transaction->debt !== null)
            ->map(fn (Transaction $transaction) => [
                'id' => 'transaction-'.$transaction->id,
                'date' => $transaction->occurred_at->toIso8601String(),
                'type' => match (true) {
                    $transaction->type === TransactionType::CurrencyExchange => 'exchange',
                    $transaction->sadcopLedgerEntry !== null => 'sadcop',
                    $transaction->type === TransactionType::Purchase => 'purchase',
                    $transaction->type === TransactionType::Expense => 'expense',
                    default => 'income',
                },
                'description' => $this->describeTransaction($transaction, $labels),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency->value,
            ]);

        $debtPayments = DebtPayment::query()
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->with('debt.debtor')
            ->get()
            ->map(fn (DebtPayment $payment) => [
                'id' => 'debt-payment-'.$payment->id,
                'date' => $payment->paid_at->toIso8601String(),
                'type' => $payment->debt->direction === DebtDirection::Receivable ? 'income' : 'expense',
                'description' => ($payment->debt->debtor?->name ?? '—').' — '.$labels['debt_payment'],
                'amount' => (float) $payment->amount,
                'currency' => $payment->debt->currency->value,
            ]);

        return $transactions->concat($debtPayments)
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function describeTransaction(Transaction $transaction, array $labels): string
    {
        if ($transaction->type === TransactionType::CurrencyExchange) {
            return number_format((float) $transaction->amount, 2).' '.$transaction->currency->value
                .' → '.number_format((float) $transaction->to_amount, 2).' '.$transaction->to_currency->value;
        }

        if ($transaction->sadcopLedgerEntry !== null) {
            return $labels['sadcop_transfer'];
        }

        return $transaction->fuelType?->name ?? $transaction->description ?? $transaction->type->value;
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
