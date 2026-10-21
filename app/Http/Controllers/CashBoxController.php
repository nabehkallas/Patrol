<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\OtherIncomeCategory;
use App\Enums\TransactionType;
use App\Models\CashBoxOpening;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\DebtPayment;
use App\Models\Shift;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use App\Support\Currency;
use App\Support\Locales;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class CashBoxController extends Controller
{
    use GroupsByCurrency;

    public function index(Request $request): Response
    {
        // "Today", "Yesterday", and "Custom Range" are kept as distinct, explicit modes rather
        // than inferring one from from/to (e.g. from === to === today) -- the picker only ever
        // writes from/to, so an explicit mode is what lets a quick-filter tab stay selected/
        // reproducible on reload without accidentally matching a genuine one-day custom range.
        $mode = $request->string('mode')->value();
        $mode = in_array($mode, ['custom', 'yesterday'], true) ? $mode : 'today';

        if ($mode === 'custom' && $request->filled('from')) {
            $from = $request->date('from');
            $to = $request->date('to') ?? now();
        } elseif ($mode === 'yesterday') {
            $from = now()->subDay()->startOfDay();
            $to = now()->subDay()->endOfDay();
        } else {
            $mode = 'today';
            $from = now()->startOfDay();
            $to = now();
        }

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        // Opening balance is the physical cash actually in the drawer -- a station-wide fact,
        // not something that belongs to whichever cashier happens to be logged in -- so it's
        // deliberately computed unscoped (isAdmin: true) even for a non-admin viewer. Scoping
        // it by user_id was the bug behind "Opening Balance always shows 0": a non-admin
        // viewer's balance was silently limited to only the transactions *they personally*
        // recorded, so it read 0 for anyone who didn't record every prior transaction
        // themselves (e.g. a new shift, or a rotating cashier).
        //
        // This app has no stored "starting cash balance" concept for Cash Box (unlike Sadcop's
        // ledger) -- it's derived the same way the XLSX export seeds its first row, just
        // reusing summarize() itself rather than duplicating its categorization rules:
        // summarize()'s own "net" for everything strictly before the range IS the balance
        // carried into it (income - expenses + exchanged - sadcop, summed since the beginning),
        // which is mathematically the same figure as chaining "yesterday's opening + yesterday's
        // net" one day at a time, just computed in one pass instead of recursively.
        $openingBalance = $this->summarize(now()->copy()->setDate(2000, 1, 1)->startOfDay(), $from->copy()->startOfDay()->subSecond(), true, $user->id)['net'];

        $cashBox = $this->summarize($from->copy()->startOfDay(), $to->copy()->endOfDay(), $isAdmin, $user->id);

        return Inertia::render('cash-box/index', [
            'filters' => [
                'mode' => $mode,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'cashBox' => $cashBox,
            'openingBalance' => $openingBalance,
            // The cash each box held when the station started (admins set it).
            'openingCash' => $isAdmin ? CashBoxOpening::orderBy('currency')->get(['id', 'currency', 'amount', 'date', 'notes']) : [],
            'history' => $this->historyEntries($from->copy()->startOfDay(), $to->copy()->endOfDay(), $isAdmin, $user->id),
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $direction = Locales::direction();

        $period = $this->summarize($from->copy()->startOfDay(), $to->copy()->endOfDay(), $isAdmin, $user->id);
        $today = $this->summarize(now()->startOfDay(), now()->endOfDay(), $isAdmin, $user->id);

        $labels = Locales::labels([
            'title' => 'Cash Box',
            'metric' => 'Metric',
            'value' => 'Value',
            'section' => 'Period',
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
        ]);

        $formatBreakdown = fn (array $breakdown) => collect($breakdown)
            ->map(fn ($amount, $currency) => number_format($amount, Currency::decimals($currency)).' '.$currency)
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
     * One row per day, mirroring the hand-kept daily ledger sheet (not the flat metric/value
     * summary the PDF export uses), with every cash box in it. For the primary currency: the
     * running cash balance, that day's sales, Sadcop payments, government ("smart card")
     * collections, other income, currency exchanged (signed) and expenses. For each other
     * currency with anything in it: its running balance, income, exchanged and expenses. Opening
     * cash entries are rows of their own, on their date, with a note.
     *
     * Every day's figures are the Cash Box's own (summarize() for that day), so the balances run
     * exactly like the page's. Balance columns are real Excel formulas carried forward from the
     * *previous* row's complete activity (same pattern as the Sadcop export's المدور column): a
     * row's own figures appear in it but only affect the *next* row's balance. The first row is
     * seeded with the balance carried into the sheet.
     *
     * "Government" is broken out from ordinary sales because it isn't cash in hand the same day
     * -- it's a debt payment received later from the government debtor (or an Other Income entry
     * in the "government" category); an Other Income entry in the "other" category gets its own
     * column, kept out of Sold. The Notes column surfaces those entries' notes.
     */
    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        $user = $request->user();
        $sheet = $this->ledgerSheet($from, $to, $user->isAdmin(), $user->id);

        return $exporter->download(
            filename: 'cash-box-'.$from->toDateString().'-to-'.$to->toDateString().'.xlsx',
            title: __('Cash Box'),
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: $sheet['headers'],
            rows: $sheet['rows'],
            direction: 'ltr',
            columnFormats: $sheet['formats'],
        );
    }

    /**
     * The ledger sheet's columns and rows (see exportXlsx()); the first data row is row 5 (title,
     * subtitle, blank, header).
     *
     * @return array{headers: list<string>, rows: list<list<mixed>>, formats: array<int, string>, currencies: list<string>}
     */
    public function ledgerSheet(CarbonInterface $from, CarbonInterface $to, bool $isAdmin, int $userId): array
    {
        $primary = Currency::primary();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        // Each day as the Cash Box sums it, and the balance carried into the sheet.
        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $days[$day->toDateString()] = $this->summarize($day->copy()->startOfDay(), $day->copy()->endOfDay(), $isAdmin, $userId);
        }
        $seed = $this->summarize(now()->setDate(2000, 1, 1)->startOfDay(), $from->copy()->subSecond(), $isAdmin, $userId)['net'];
        $openings = ($isAdmin ? $this->openingsBetween($from, $to->copy()->endOfDay()) : collect())
            ->groupBy(fn (CashBoxOpening $opening): string => $opening->date->toDateString());

        // The primary currency's income split: government and "other" apart from ordinary sales.
        $transactions = Transaction::query()
            ->where('type', TransactionType::OtherIncome)
            ->where('currency', $primary)
            ->whereIn('other_income_category', [OtherIncomeCategory::Government, OtherIncomeCategory::Other])
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $userId))
            ->with('debt')
            ->get()
            ->reject(fn (Transaction $t) => $t->debt !== null);
        $governmentDebtorId = Debtor::government()->id;
        $governmentPayments = DebtPayment::query()
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->whereHas('debt', fn ($q) => $q->where('direction', DebtDirection::Receivable)->where('debtor_id', $governmentDebtorId))
            ->with('debt')
            ->get()
            ->filter(fn (DebtPayment $p) => $p->cashCurrency() === $primary);
        $byDay = fn (Collection $items, \Closure $date) => $items->groupBy(fn ($item): string => $date($item)->toDateString());
        $governmentIncomeByDay = $byDay($transactions->where('other_income_category', OtherIncomeCategory::Government), fn (Transaction $t) => $t->occurred_at);
        $otherCategoryByDay = $byDay($transactions->where('other_income_category', OtherIncomeCategory::Other), fn (Transaction $t) => $t->occurred_at);
        $governmentPaymentsByDay = $byDay($governmentPayments, fn (DebtPayment $p) => $p->paid_at);

        // Every other currency that has anything in the sheet: a balance, activity or opening cash.
        $others = collect(array_keys($seed))
            ->merge(collect($days)->flatMap(fn (array $day) => [...array_keys($day['income']), ...array_keys($day['other_expense']), ...array_keys($day['exchanged'])]))
            ->merge($openings->flatten()->pluck('currency'))
            ->filter(fn ($code) => $code !== $primary && (abs($seed[$code] ?? 0) > 0.005 || collect($days)->contains(fn (array $day) => abs($day['income'][$code] ?? 0) + abs($day['other_expense'][$code] ?? 0) + abs($day['exchanged'][$code] ?? 0) > 0.005) || $openings->flatten()->contains('currency', $code)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // The columns, by key: [header, letter]. Sadcop is always paid in SYP.
        $columns = [];
        $add = function (string $key, string $header) use (&$columns): void {
            $columns[$key] = [$header, Coordinate::stringFromColumnIndex(count($columns) + 1)];
        };
        $add('date', __('Date'));
        $add("$primary.balance", __('Cash Box (:currency)', ['currency' => $primary]));
        $add("$primary.sold", __('Sold (:currency)', ['currency' => $primary]));
        $add('sadcop', __('Sadcop Payments'));
        $add("$primary.government", __('Government'));
        $add("$primary.other", __('Other Income'));
        $add("$primary.exchanged", __('Exchanged (:currency)', ['currency' => $primary]));
        $add("$primary.expense", __('Expense (:currency)', ['currency' => $primary]));
        foreach ($others as $code) {
            $add("$code.balance", __('Cash Box (:currency)', ['currency' => $code]));
            $add("$code.income", __('Income (:currency)', ['currency' => $code]));
            $add("$code.exchanged", __('Exchanged (:currency)', ['currency' => $code]));
            $add("$code.expense", __('Expense (:currency)', ['currency' => $code]));
        }
        $add('notes', __('Notes'));
        $letter = fn (string $key) => $columns[$key][1];

        // A box's balance: the seed on the first row, then the previous row's balance plus what
        // came in and minus what went out on it.
        $balanceFormula = function (string $code, int $previous) use ($primary, $letter): string {
            if ($code === $primary) {
                $in = ["$code.sold", "$code.government", "$code.other", "$code.exchanged"];
                $out = ["$code.expense", ...($code === Currency::SYP ? ['sadcop'] : [])];
            } else {
                // (Sadcop paid in SYP on a station whose primary currency isn't SYP is already
                // in that SYP box's expense column.)
                $in = ["$code.income", "$code.exchanged"];
                $out = ["$code.expense"];
            }

            return '='.$letter("$code.balance").$previous
                .implode('', array_map(fn (string $key) => '+'.$letter($key).$previous, $in))
                .implode('', array_map(fn (string $key) => '-'.$letter($key).$previous, $out));
        };
        $sadcopInPrimary = $primary === Currency::SYP;

        $rows = [];
        $firstDataRow = 5;
        $value = fn (float $amount, string $code) => abs($amount) > 0.005 ? round($amount, Currency::decimals($code)) : null;
        $emit = function (array $cells) use (&$rows, $columns, $primary, $others, $seed, $firstDataRow, $balanceFormula): void {
            $thisRow = $firstDataRow + count($rows);
            foreach ([$primary, ...$others] as $code) {
                $cells["$code.balance"] = $rows === []
                    ? round($seed[$code] ?? 0.0, Currency::decimals($code))
                    : $balanceFormula($code, $thisRow - 1);
            }
            $rows[] = array_map(fn (string $key) => $cells[$key] ?? null, array_keys($columns));
        };

        foreach ($days as $dayKey => $day) {
            // Opening cash on this day: its own row, before the day's activity.
            foreach ($openings->get($dayKey, collect()) as $opening) {
                $code = $opening->currency;
                $emit([
                    'date' => $dayKey,
                    ($code === $primary ? "$code.other" : "$code.income") => round((float) $opening->amount, Currency::decimals($code)),
                    'notes' => trim(__('Opening cash').' ('.$code.') '.($opening->notes ?? '')),
                ]);
            }

            $government = (float) $governmentIncomeByDay->get($dayKey, collect())->sum('amount')
                + (float) $governmentPaymentsByDay->get($dayKey, collect())->sum(fn (DebtPayment $p) => $p->cashAmount());
            $other = (float) $otherCategoryByDay->get($dayKey, collect())->sum('amount');
            $sadcop = (float) $day['sadcop_expense_syp'];
            $cells = [
                'date' => $dayKey,
                "$primary.sold" => $value(($day['income'][$primary] ?? 0.0) - $government - $other, $primary),
                'sadcop' => $value($sadcop, Currency::SYP),
                "$primary.government" => $value($government, $primary),
                "$primary.other" => $value($other, $primary),
                "$primary.exchanged" => $value($day['exchanged'][$primary] ?? 0.0, $primary),
                "$primary.expense" => $value($day['other_expense'][$primary] ?? 0.0, $primary),
                'notes' => $governmentIncomeByDay->get($dayKey, collect())->concat($otherCategoryByDay->get($dayKey, collect()))
                    ->pluck('notes')->filter()->implode('; ') ?: null,
            ];
            foreach ($others as $code) {
                $cells["$code.income"] = $value($day['income'][$code] ?? 0.0, $code);
                $cells["$code.exchanged"] = $value($day['exchanged'][$code] ?? 0.0, $code);
                // Sadcop is SYP; on a station whose primary currency isn't SYP it's an SYP expense.
                $cells["$code.expense"] = $value(($day['other_expense'][$code] ?? 0.0) + ($code === Currency::SYP && ! $sadcopInPrimary ? $sadcop : 0.0), $code);
            }
            if (! $sadcopInPrimary) {
                $cells['sadcop'] = null;
            }
            $emit($cells);
        }

        $formats = [];
        foreach (array_keys($columns) as $index => $key) {
            if (! in_array($key, ['date', 'notes'], true)) {
                $formats[$index] = '#,##0.00';
            }
        }

        return [
            'headers' => array_values(array_map(fn (array $column) => $column[0], $columns)),
            'rows' => $rows,
            'formats' => $formats,
            'currencies' => [$primary, ...$others],
        ];
    }

    /**
     * Income, expenses (split between Sadcop payments and other expenses), debts, and liters
     * sold for the given range — mirrors the dashboard's cash box totals. Fuel deliveries never
     * touch the attendant's cash register, so they're excluded entirely (they're tracked as
     * inventory movements, not cash flow).
     *
     * @return array<string, mixed>
     */
    public function summarize(CarbonInterface $from, CarbonInterface $to, bool $isAdmin, int $userId): array
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

        // Sadcop is always paid in SYP: a plain sum of the transfers, no exchange rate involved.
        $sadcopExpenseSyp = (float) $sadcopTransactions->sum(fn (Transaction $transaction) => (float) $transaction->amount);

        // Payments made within this window — whether they fully settle a debt or only chip
        // away at it — are cash that actually arrived (receivable) or went out (payable) today.
        // A debt's own settlement is no longer the source of truth for *when* cash moved: each
        // payment is, since a debt can now be paid off across several partial payments.
        $receivablePayments = $this->debtPaymentsFor(DebtDirection::Receivable, $from, $to, $isAdmin, $userId);
        $payablePayments = $this->debtPaymentsFor(DebtDirection::Payable, $from, $to, $isAdmin, $userId);

        $incomeBreakdown = $this->byCurrency($incomeTransactions->concat($receivablePayments));
        $otherExpenseBreakdown = $this->byCurrency($otherExpenseTransactions->concat($payablePayments));

        // Income by source, each in its own currency (no conversion): the dashboard's income
        // breakdown popover shows the same currency as the income card it belongs to.
        $fuelSaleIncomeTransactions = $incomeTransactions->where('type', TransactionType::FuelSale);

        $incomeBySource = [
            'fuel_sales' => $this->byCurrency($fuelSaleIncomeTransactions),
            'fuel_sales_by_type' => $this->fuelRevenueByType($fuelSaleIncomeTransactions),
            'store_income' => $this->byCurrency($incomeTransactions->where('type', TransactionType::OtherIncome)),
            'debt_collections' => $this->byCurrency($receivablePayments),
        ];

        $exchangeTransactions = $transactions->where('type', TransactionType::CurrencyExchange);
        $exchangedBreakdown = $this->exchangedByCurrency($exchangeTransactions);

        // The cash each box already held when the station started (one entry per box, on its
        // date). A station-wide figure, so a cashier's own view of a period leaves it out.
        $openingCash = $this->byCurrency($isAdmin ? $this->openingsBetween($from, $to) : collect());

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
            ->sum(fn (Debt $debt) => (float) ($debt->liters ?? $debt->transaction->liters ?? 0));

        return [
            'income' => $incomeBreakdown,
            'income_by_source' => $incomeBySource,
            'sadcop_expense_syp' => round($sadcopExpenseSyp, 0),
            'other_expense' => $otherExpenseBreakdown,
            'exchanged' => $exchangedBreakdown,
            'opening_cash' => $openingCash,
            'net' => $this->netByCurrency($incomeBreakdown, $otherExpenseBreakdown, $sadcopExpenseSyp, $exchangedBreakdown, $openingCash),
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
     * Fuel-sale revenue per fuel type and currency, for the dashboard's "Total Income"
     * breakdown -- liters and revenue are both summed straight from the transactions, and unit
     * price is derived from them (revenue / liters) rather than looked up separately, so it
     * always reconciles exactly as "liters x unit price = revenue" even if a fuel type's price
     * changed mid-range. Each currency stays its own row; nothing is converted.
     *
     * @param  Collection<int, Transaction>  $fuelSaleIncomeTransactions
     * @return array<int, array{name: string, currency: string, liters: float, unit_price: float, revenue: float}>
     */
    private function fuelRevenueByType(Collection $fuelSaleIncomeTransactions): array
    {
        return $fuelSaleIncomeTransactions
            ->filter(fn (Transaction $transaction) => $transaction->fuel_type_id !== null)
            ->groupBy(fn (Transaction $transaction) => $transaction->fuel_type_id.'|'.$transaction->currency)
            ->map(function ($group) {
                $currency = $group->first()->currency;
                $liters = round((float) $group->sum('liters'), 3);
                $revenue = round((float) $group->sum(fn (Transaction $t) => (float) $t->amount), Currency::decimals($currency));

                return [
                    'name' => $group->first()->fuelType?->name,
                    'currency' => $currency,
                    'liters' => $liters,
                    'unit_price' => $liters > 0 ? round($revenue / $liters, 2) : 0,
                    'revenue' => $revenue,
                ];
            })
            ->filter(fn (array $row) => $row['name'] !== null)
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Opening cash entries dated within a window.
     *
     * @return Collection<int, CashBoxOpening>
     */
    private function openingsBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return CashBoxOpening::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
    }

    /**
     * Debt payments of the given direction within a window, as plain currency/amount pairs of
     * the cash that moved: the debt's currency, unless it was paid from another box at a rate.
     *
     * @return Collection<int, object{currency: string, amount: float}&\stdClass>
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
                'currency' => $payment->cashCurrency(),
                'amount' => $payment->cashAmount(),
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
     * @return array<int, array{id: string, date: string, entered: array{at: string, shift: string|null}|null, type: string, description: string, amount: float, currency: string, original: array{amount: float, currency: string, rate: float}|null}>
     */
    private function historyEntries(CarbonInterface $from, CarbonInterface $to, bool $isAdmin, int $userId): array
    {
        $labels = Locales::labels([
            'sadcop_transfer' => 'Sadcop transfer',
            'debt_payment' => 'Debt payment',
            'opening_cash' => 'Opening cash',
        ]);

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
            ->with(['fuelType', 'debt', 'sadcopLedgerEntry', 'pumpCounterReading', 'governmentalReading'])
            ->get()
            // A transaction with a debt is represented by that debt's payment entries instead
            // (below) — it hasn't moved cash yet if outstanding, and once paid (in full or in
            // part), the payment date(s) — not this transaction's date — are when cash moved.
            ->reject(fn (Transaction $transaction) => $transaction->debt !== null);

        // Shifts are only looked up for a sale entered late (rare, and loaded one by one).
        $withShift = $transactions->contains(fn (Transaction $transaction) => $transaction->enteredLate())
            && Shift::severalActive();

        $transactions = $transactions
            ->map(fn (Transaction $transaction) => [
                'id' => 'transaction-'.$transaction->id,
                // A fuel sale saved on a later day than its reading's: its date only, and when
                // it was entered (Transaction::shownTime()); last on that date.
                'date' => $transaction->shownTime()['at'],
                'entered' => $transaction->shownTime($withShift)['entered'],
                'sort' => $transaction->shownSortKey(),
                'type' => match (true) {
                    $transaction->type === TransactionType::CurrencyExchange => 'exchange',
                    $transaction->sadcopLedgerEntry !== null => 'sadcop',
                    $transaction->type === TransactionType::Purchase => 'purchase',
                    $transaction->type === TransactionType::Expense => 'expense',
                    default => 'income',
                },
                'description' => $this->describeTransaction($transaction, $labels),
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'original' => $transaction->original_currency !== null ? [
                    'amount' => (float) $transaction->original_amount,
                    'currency' => $transaction->original_currency,
                    'rate' => (float) $transaction->original_rate,
                ] : null,
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
                'entered' => null,
                'sort' => $payment->paid_at->format('Y-m-d H:i:s'),
                'type' => $payment->debt->direction === DebtDirection::Receivable ? 'income' : 'expense',
                'description' => ($payment->debt->debtor->name ?? '—').' — '.$labels['debt_payment'],
                'amount' => $payment->cashAmount(),
                'currency' => $payment->cashCurrency(),
                'original' => $payment->cash_currency !== null ? [
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->debt->currency,
                    'rate' => (float) $payment->cash_rate,
                ] : null,
            ]);

        $openings = ($isAdmin ? $this->openingsBetween($from, $to) : collect())
            ->map(fn (CashBoxOpening $opening) => [
                'id' => 'opening-'.$opening->id,
                'date' => $opening->date->copy()->startOfDay()->toIso8601String(),
                'entered' => null,
                'sort' => $opening->date->copy()->startOfDay()->format('Y-m-d H:i:s'),
                'type' => 'opening',
                'description' => $opening->notes ?: $labels['opening_cash'],
                'amount' => (float) $opening->amount,
                'currency' => $opening->currency,
                'original' => null,
            ]);

        return $transactions->concat($debtPayments)->concat($openings)
            ->sortByDesc('sort')
            ->map(function (array $entry) {
                unset($entry['sort']);

                return $entry;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function describeTransaction(Transaction $transaction, array $labels): string
    {
        if ($transaction->type === TransactionType::CurrencyExchange) {
            return number_format((float) $transaction->amount, 2).' '.$transaction->currency
                .' → '.number_format((float) $transaction->to_amount, 2).' '.$transaction->to_currency;
        }

        if ($transaction->sadcopLedgerEntry !== null) {
            // A Sadcop deposit isn't earmarked for a specific fuel type at payment time (it
            // just tops up a shared balance that later deliveries draw down) -- notes are the
            // only place a cashier can record what a payment was actually for, so prefer that
            // over the generic label when it's there.
            return $transaction->notes ?: $labels['sadcop_transfer'];
        }

        // Only a fuel sale or delivery is labelled by its fuel; anything else by its own
        // description (expenses saved before this was fixed still carry a tank's fuel type).
        $isFuel = in_array($transaction->type, [TransactionType::FuelSale, TransactionType::FuelDelivery], true);

        return ($isFuel ? $transaction->fuelType?->name : null)
            ?: ($transaction->description ?: __('transactions.type.'.$transaction->type->value));
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
            $from = $transaction->currency;
            $to = $transaction->to_currency;

            $totals[$from] = ($totals[$from] ?? 0.0) - (float) $transaction->amount;
            $totals[$to] = ($totals[$to] ?? 0.0) + (float) $transaction->to_amount;
        }

        $result = [];

        foreach ($totals as $currency => $amount) {
            $rounded = round($amount, Currency::decimals($currency));

            if ($currency === Currency::primary() || $rounded != 0) {
                $result[$currency] = $rounded;
            }
        }

        return $result;
    }

    /**
     * Income minus expenses plus the net effect of currency exchanges, per currency, with the
     * station's primary currency always first — sadcop expenses are always SYP by construction
     * (see SadcopController), so they only ever reduce the SYP side.
     *
     * @param  array<string, float>  $income
     * @param  array<string, float>  $otherExpense
     * @param  array<string, float>  $exchanged
     * @param  array<string, float>  $openingCash
     * @return array<string, float>
     */
    private function netByCurrency(array $income, array $otherExpense, float $sadcopExpenseSyp, array $exchanged, array $openingCash = []): array
    {
        $primary = Currency::primary();
        $currencies = array_unique([$primary, ...array_keys($income), ...array_keys($otherExpense), ...array_keys($exchanged), ...array_keys($openingCash), ...($sadcopExpenseSyp != 0 ? [Currency::SYP] : [])]);

        $result = [];

        foreach ($currencies as $currency) {
            $value = ($income[$currency] ?? 0.0)
                + ($openingCash[$currency] ?? 0.0)
                - ($otherExpense[$currency] ?? 0.0)
                + ($exchanged[$currency] ?? 0.0)
                - ($currency === Currency::SYP ? $sadcopExpenseSyp : 0.0);

            $rounded = round($value, Currency::decimals($currency));

            if ($currency === $primary || $rounded != 0) {
                $result[$currency] = $rounded;
            }
        }

        return $result;
    }
}
