<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\Currency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Http\Requests\StoreDebtPaymentRequest;
use App\Http\Requests\StoreDebtRequest;
use App\Http\Requests\TransferDebtRequest;
use App\Http\Requests\UpdateDebtRequest;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\FuelType;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DebtController extends Controller
{
    use GroupsByCurrency;

    public function index(Request $request): Response
    {
        $query = $this->filteredQuery($request);

        $debts = $query->paginate(25)->withQueryString();
        $debts->getCollection()->transform(function (Debt $debt) {
            $debt->remaining_amount = $debt->remainingAmount();
            $debt->paid_amount = $debt->paidAmount();

            return $debt;
        });

        return Inertia::render('debts/index', [
            'debts' => $debts,
            'filters' => $request->only(['search', 'status', 'direction', 'sort', 'sort_dir', 'debtor_id']),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'totals' => [
                'outstanding' => $this->outstandingTotal($request, DebtDirection::Receivable),
                'total' => $this->allDebtsTotal($request, DebtDirection::Receivable),
                'payable_outstanding' => $this->outstandingTotal($request, DebtDirection::Payable),
                'payable_total' => $this->allDebtsTotal($request, DebtDirection::Payable),
            ],
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Debt::with(['recordedBy', 'debtor', 'fuelType', 'transaction.fuelType', 'payments']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function (Builder $query) use ($search) {
                $query->whereHas('debtor', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhere('details', 'like', "%{$search}%");
            });
        }

        if ($request->filled('debtor_id')) {
            $query->whereIn('debtor_id', $this->debtorIdsFor($request->integer('debtor_id')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction'));
        }

        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        if ($request->string('sort')->toString() === 'status') {
            $query->orderBy('status', $sortDir)->orderBy('date', 'desc');
        } else {
            $query->orderBy('date', $sortDir);
        }

        return $query;
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
        $debts = $this->filteredQuery($request)->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'الديون',
            'date' => 'التاريخ',
            'debtor' => 'المدين',
            'what_for' => 'سبب الدين',
            'amount' => 'المبلغ',
            'direction' => 'الاتجاه',
            'status' => 'الحالة',
            'recorded_by' => 'سجّله',
            'outstanding' => 'غير مسدد',
            'settled' => 'مسدد',
            'total' => 'الإجمالي',
            'receivable' => 'لنا',
            'payable' => 'علينا',
            'owed_to_us' => 'مستحق لنا',
            'we_owe' => 'مستحق علينا',
        ] : [
            'title' => 'Debts',
            'date' => 'Date',
            'debtor' => 'Debtor',
            'what_for' => 'What for',
            'amount' => 'Amount',
            'direction' => 'Direction',
            'status' => 'Status',
            'recorded_by' => 'Recorded by',
            'outstanding' => 'Outstanding',
            'settled' => 'Settled',
            'total' => 'Total',
            'receivable' => 'Owed to us',
            'payable' => 'We owe',
            'owed_to_us' => 'Owed to us',
            'we_owe' => 'We owe',
        ];

        $rows = $debts->map(function (Debt $debt) use ($labels) {
            $fuelTypeName = $debt->transaction?->fuelType?->name ?? $debt->fuelType?->name;
            $liters = $debt->liters ?? $debt->transaction?->liters;
            $whatFor = $fuelTypeName && $liters
                ? $fuelTypeName.' — '.number_format((float) $liters, 3).' L'
                : ($fuelTypeName ?? $debt->details ?? '—');

            return [
                $debt->date->format('Y-m-d'),
                $debt->debtor?->name ?? '—',
                $whatFor,
                number_format((float) $debt->amount, 1).' '.$debt->currency->value,
                $debt->direction === DebtDirection::Payable ? $labels['payable'] : $labels['receivable'],
                $debt->status === DebtStatus::Outstanding ? $labels['outstanding'] : $labels['settled'],
                $debt->recordedBy?->name ?? '—',
            ];
        })->all();

        $formatTotal = fn (array $breakdown) => collect($breakdown)
            ->map(fn ($amount, $currency) => number_format($amount, $currency === 'SYP' ? 0 : 2).' '.$currency)
            ->implode(' + ');

        $totalBreakdown = $labels['owed_to_us'].': '.$formatTotal($this->allDebtsTotal($request, DebtDirection::Receivable))
            .' — '.$labels['we_owe'].': '.$formatTotal($this->allDebtsTotal($request, DebtDirection::Payable));

        return $exporter->download(
            filename: 'debts-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: $totalBreakdown,
            headers: [$labels['date'], $labels['debtor'], $labels['what_for'], $labels['amount'], $labels['direction'], $labels['status'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    private function outstandingTotal(Request $request, DebtDirection $direction): array
    {
        $query = Debt::where('status', DebtStatus::Outstanding)->where('direction', $direction);

        if ($request->filled('debtor_id')) {
            $query->whereIn('debtor_id', $this->debtorIdsFor($request->integer('debtor_id')));
        }

        return $this->byCurrency($query->with('payments')->get(), fn (Debt $debt) => $debt->remainingAmount());
    }

    private function allDebtsTotal(Request $request, DebtDirection $direction): array
    {
        $query = Debt::where('direction', $direction);

        if ($request->filled('debtor_id')) {
            $query->whereIn('debtor_id', $this->debtorIdsFor($request->integer('debtor_id')));
        }

        return $this->byCurrency($query->get());
    }

    /**
     * A debtor filter should include its sub-debtors too — a parent's debts view is meant to
     * roll up the whole family, matching the consolidated total already shown on the Debtors
     * page. Filtering by a sub-debtor itself just returns its own id (a sub-debtor can't have
     * children of its own).
     *
     * @return array<int, int>
     */
    private function debtorIdsFor(int $debtorId): array
    {
        return [$debtorId, ...Debtor::where('parent_id', $debtorId)->pluck('id')];
    }

    public function create(): Response
    {
        return Inertia::render('debts/create', [
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'fuelTypes' => $this->fuelTypeOptions(),
            'exchangeRates' => collect(Currency::cases())->mapWithKeys(
                fn (Currency $currency) => [$currency->value => ExchangeRate::currentRateFor($currency)]
            ),
        ]);
    }

    private function fuelTypeOptions()
    {
        return FuelType::orderBy('name')->get()->map(fn (FuelType $fuelType) => [
            'id' => $fuelType->id,
            'name' => $fuelType->name,
            'currentPrice' => $fuelType->currentPrice()?->only(['price_per_liter', 'currency']),
        ]);
    }

    public function store(StoreDebtRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['recorded_by_id'] = $request->user()->id;
        $data['status'] = DebtStatus::Outstanding->value;

        if (empty($data['exchange_rate_to_usd'])) {
            $data['exchange_rate_to_usd'] = ExchangeRate::currentRateFor(Currency::from($data['currency']));
        }

        $affectCashBoxNow = $data['affect_cash_box'] ?? false;
        unset($data['affect_cash_box']);

        DB::transaction(function () use ($request, $data, $affectCashBoxNow) {
            $debt = Debt::create($data);

            if ($affectCashBoxNow) {
                $this->recordImmediateCashEffect($debt, $request->user()->id);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt recorded.')]);

        return to_route('debts.index');
    }

    /**
     * Some debts represent cash that actually moved right now — e.g. handing someone cash as
     * an advance (receivable: it leaves the register now, comes back when they repay) or
     * borrowing cash from someone (payable: it arrives now, leaves again when repaid). The
     * debt's own settlement already produces the "money returns" side (see
     * CashBoxController::historyEntries()); this transaction is the "money leaves/arrives now"
     * side, recorded as an ordinary transaction so it shows up in the Cash Box immediately.
     */
    private function recordImmediateCashEffect(Debt $debt, int $userId): void
    {
        Transaction::create([
            'user_id' => $userId,
            'type' => $debt->direction === DebtDirection::Receivable ? TransactionType::Expense : TransactionType::OtherIncome,
            'description' => ($debt->debtor?->name ?? '—').' — '.__('cash advance'),
            'amount' => $debt->amount,
            'currency' => $debt->currency,
            'exchange_rate_to_usd' => $debt->exchange_rate_to_usd,
            'occurred_at' => Carbon::parse($debt->date)->setTimeFrom(now()),
            'notes' => $debt->details,
        ]);
    }

    public function edit(Debt $debt): Response
    {
        $this->authorize('update', $debt);

        return Inertia::render('debts/edit', [
            'debt' => $debt->only([
                'id', 'direction', 'debtor_id', 'fuel_type_id', 'liters', 'price_per_liter', 'amount', 'currency',
                'exchange_rate_to_usd', 'date', 'details', 'status',
            ]),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'fuelTypes' => $this->fuelTypeOptions(),
            'exchangeRates' => collect(Currency::cases())->mapWithKeys(
                fn (Currency $currency) => [$currency->value => ExchangeRate::currentRateFor($currency)]
            ),
        ]);
    }

    public function update(UpdateDebtRequest $request, Debt $debt): RedirectResponse
    {
        $this->authorize('update', $debt);

        $data = $request->validated();

        if ($data['status'] === DebtStatus::Settled->value && $debt->status !== DebtStatus::Settled) {
            $data['settled_at'] = now();
        } elseif ($data['status'] === DebtStatus::Outstanding->value) {
            $data['settled_at'] = null;
        }

        $debt->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt updated.')]);

        return to_route('debts.index');
    }

    public function destroy(Debt $debt): RedirectResponse
    {
        $this->authorize('delete', $debt);

        $debt->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt deleted.')]);

        return to_route('debts.index');
    }

    public function settle(Request $request, Debt $debt): RedirectResponse
    {
        $this->authorize('settle', $debt);

        if ($debt->status !== DebtStatus::Settled) {
            $debt->recordPayment($debt->remainingAmount(), $request->user()->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt settled.')]);

        return to_route('debts.index');
    }

    /**
     * Bulk-settles every outstanding debt matching the current filters (debtor, search,
     * direction) whose own `date` falls within the given range, in full — e.g. "settle
     * everything owed by this debtor from the 1st through the 10th" in one action instead of
     * clicking Settle on each row. The payment is booked on the range's end date.
     */
    public function settleFiltered(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $paidAt = Carbon::parse($data['to'])->setTimeFrom(now());

        $debts = $this->filteredQuery($request)
            ->where('status', DebtStatus::Outstanding)
            ->whereDate('date', '>=', $data['from'])
            ->whereDate('date', '<=', $data['to'])
            ->get();

        DB::transaction(function () use ($debts, $paidAt, $request) {
            foreach ($debts as $debt) {
                // filteredQuery() eager-loads `payments` for the index totals — remainingAmount()
                // would otherwise sum that now-stale cached collection instead of re-querying
                // after recordPayment() inserts the new row, and never see the debt as settled.
                $debt->unsetRelation('payments');
                $debt->recordPayment($debt->remainingAmount(), $request->user()->id, null, $paidAt);
            }
        });

        $message = $debts->isEmpty()
            ? __('No outstanding debts matched these filters.')
            : __('Debts settled.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('debts.index', $request->only(['search', 'status', 'direction', 'sort', 'sort_dir', 'debtor_id']));
    }

    public function storePayment(StoreDebtPaymentRequest $request, Debt $debt): RedirectResponse
    {
        $this->authorize('settle', $debt);

        $remaining = $debt->remainingAmount();
        $amount = (float) $request->validated('amount');

        if ($amount > $remaining + 0.01) {
            throw ValidationException::withMessages([
                'amount' => __('This exceeds the remaining balance (:remaining).', ['remaining' => number_format($remaining, 2)]),
            ]);
        }

        $debt->recordPayment($amount, $request->user()->id);

        $message = $debt->fresh()->status === DebtStatus::Settled
            ? __('Debt settled.')
            : __('Payment recorded.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('debts.index');
    }

    public function transfer(TransferDebtRequest $request, Debt $debt): RedirectResponse
    {
        $debt->update(['debtor_id' => $request->validated('debtor_id')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt transferred.')]);

        return to_route('debts.index');
    }
}
