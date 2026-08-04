<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CashBoxController extends Controller
{
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
            'net' => 'Net',
            'liters_sold' => 'Liters sold',
            'debts' => 'Debts (unsettled)',
            'debts_liters' => 'Liters sold in debt (unsettled)',
        ];

        $rowsFor = fn (array $summary) => [
            [$labels['income'], number_format($summary['income_syp'], 0).' SYP'],
            [$labels['sadcop'], number_format($summary['sadcop_expense_syp'], 0).' SYP'],
            [$labels['other_expenses'], number_format($summary['other_expense_syp'], 0).' SYP'],
            [$labels['net'], number_format($summary['net_syp'], 0).' SYP'],
            [$labels['liters_sold'], number_format($summary['liters_sold'], 3).' L'],
            [$labels['debts'], number_format($summary['debts_syp'], 0).' SYP'],
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

        $debts = Debt::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when(! $isAdmin, fn ($q) => $q->where('recorded_by_id', $userId))
            ->with(['fuelType', 'transaction'])
            ->get();

        // The "debts" card reflects money still owed, so it only counts unsettled debts —
        // but liters sold on credit are real fuel movements and stay in $litersSold below
        // regardless of settlement status.
        $outstandingDebts = $debts->where('status', DebtStatus::Outstanding);

        $incomeSyp = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $transaction) => $transaction->isPendingDebt())
            ->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));

        $expenseTransactions = $transactions
            ->where('type', TransactionType::Expense)
            ->reject(fn (Transaction $transaction) => $transaction->isPendingDebt());

        $isSadcopPayment = fn (Transaction $transaction) => $transaction->sadcopLedgerEntry !== null;

        $sadcopExpenseSyp = $expenseTransactions
            ->filter($isSadcopPayment)
            ->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));

        $otherExpenseSyp = $expenseTransactions
            ->reject($isSadcopPayment)
            ->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));

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
            'income_syp' => round($incomeSyp, 0),
            'sadcop_expense_syp' => round($sadcopExpenseSyp, 0),
            'other_expense_syp' => round($otherExpenseSyp, 0),
            'expense_syp' => round($sadcopExpenseSyp + $otherExpenseSyp, 0),
            'net_syp' => round($incomeSyp - $sadcopExpenseSyp - $otherExpenseSyp, 0),
            'liters_sold' => round($litersSold, 3),
            'debts_syp' => round($outstandingDebts->sum(fn (Debt $debt) => $debt->amountInSyp($sypRate)), 0),
            'debts_liters_sold' => round($litersSoldInDebt, 3),
        ];
    }
}
