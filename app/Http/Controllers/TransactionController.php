<?php

namespace App\Http\Controllers;

use App\Concerns\PaysFromCashBox;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\Shift;
use App\Models\Tank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBox;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use App\Support\Currency;
use App\Support\ExportTable;
use App\Support\Locales;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    use PaysFromCashBox;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = $this->filteredQuery($request);
        $withShift = Shift::severalActive();

        return Inertia::render('transactions/index', [
            'transactions' => $query->paginate(25)->withQueryString()
                ->through(fn (Transaction $transaction) => [
                    ...Arr::except($transaction->toArray(), ['pump_counter_reading', 'governmental_reading']),
                    'managed_by' => $transaction->managedBy(),
                    'shown' => $transaction->shownTime($withShift),
                ]),
            'users' => $user->isAdmin() ? User::orderBy('name')->get(['id', 'name']) : [],
            'filters' => [
                ...$request->only(['type', 'user_id']),
                'from' => $this->period($request)[0]->toDateString(),
                'to' => $this->period($request)[1]->toDateString(),
            ],
        ]);
    }

    /**
     * @return Builder<Transaction>
     */
    private function filteredQuery(Request $request): Builder
    {
        $user = $request->user();

        [$from, $to] = $this->period($request);

        $query = Transaction::with(['user', 'fuelType', 'tank', 'debt.debtor', 'sadcopLedgerEntry', 'pumpCounterReading.shift', 'governmentalReading.shift'])
            ->whereBetween('occurred_at', [$from, $to])
            ->latestShown();

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return $query;
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        return $this->exportTable($request)->pdf($exporter);
    }

    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        return $this->exportTable($request)->xlsx($exporter);
    }

    /** The filtered log as an export: the same columns in the PDF and the spreadsheet. */
    private function exportTable(Request $request): ExportTable
    {
        [$from, $to] = $this->period($request);
        $labels = Locales::labels([
            'title' => 'Transactions',
            'date' => 'Date',
            'type' => 'Type',
            'description' => 'Description',
            'liters' => 'Liters',
            'amount' => 'Amount',
            'currency' => 'common.currency',
            'recorded_by' => 'Recorded by',
            'types' => collect(TransactionType::cases())
                ->mapWithKeys(fn (TransactionType $type) => [$type->value => 'transactions.type.'.$type->value])
                ->all(),
        ]);

        $withShift = Shift::severalActive();

        $rows = $this->filteredQuery($request)->get()->map(fn (Transaction $transaction) => [
            $this->shownCell($transaction, $withShift),
            $labels['types'][$transaction->type->value] ?? $transaction->type->value,
            collect([$transaction->description ?? $transaction->fuelType->name ?? null, $transaction->originalNote() !== null ? '('.$transaction->originalNote().')' : null])->filter()->implode(' ') ?: null,
            $transaction->liters !== null ? (float) $transaction->liters : null,
            (float) $transaction->amount,
            $transaction->currency,
            $transaction->user->name ?? null,
        ])->values()->all();

        return new ExportTable(
            name: 'transactions',
            title: $labels['title'],
            subtitle: $from->format('Y-m-d').' – '.$to->format('Y-m-d'),
            headers: [$labels['date'], $labels['type'], $labels['description'], $labels['liters'], $labels['amount'], $labels['currency'], $labels['recorded_by']],
            rows: $rows,
            decimals: [3 => 3, 4 => fn (array $row) => Currency::decimals((string) $row[5])],
        );
    }

    /** Transaction::shownTime() as one cell: "2026-10-21 02:19", or "2026-10-21 02:19 · Morning". */
    private function shownCell(Transaction $transaction, bool $withShift): string
    {
        $shown = $transaction->shownTime($withShift);

        return collect([Carbon::parse($shown['at'])->format('Y-m-d H:i'), $shown['shift']])->filter()->implode(' · ');
    }

    /**
     * The log's date range: from/to in the query string, this month by default (like the other
     * logs). A malformed date is dropped by SanitizeDateFilters before it gets here.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function period(Request $request): array
    {
        return [
            ($request->date('from') ?? now()->startOfMonth())->startOfDay(),
            ($request->date('to') ?? now())->endOfDay(),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('transactions/create', [
            'tanks' => $this->tankOptions(),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'exchangeRates' => collect(Currency::codes())->mapWithKeys(
                fn (string $currency) => [$currency => ExchangeRate::currentRateFor($currency)]
            ),
            ...app(CashBox::class)->formProps(),
        ]);
    }

    public function store(StoreTransactionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        // A plain date picked in the form keeps today's time-of-day rather than collapsing to
        // midnight, so same-day entries still sort in the order they were actually recorded.
        $data['occurred_at'] = isset($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])->setTimeFrom(now())
            : now();

        $data = $this->withFuelFields($data);

        // Only a currency exchange carries a rate -- the one entered for that exchange. No other
        // record uses or stores one (amounts always stay in their own currency).
        if ($data['type'] !== TransactionType::CurrencyExchange->value) {
            $data['exchange_rate_to_usd'] = null;
        }

        $markAsDebt = ($data['mark_as_debt'] ?? false) && $data['type'] !== TransactionType::CurrencyExchange->value;
        $debtDebtorId = $data['debt_debtor_id'] ?? null;
        $debtDirection = $data['debt_direction'] ?? DebtDirection::Receivable->value;
        unset($data['mark_as_debt'], $data['debt_debtor_id'], $data['debt_direction']);
        $data = $this->withCashSource($request, $data, $markAsDebt);

        DB::transaction(function () use ($data, $markAsDebt, $debtDebtorId, $debtDirection) {
            $transaction = Transaction::create($data);

            if ($markAsDebt) {
                $transaction->debt()->create([
                    'direction' => $debtDirection,
                    'debtor_id' => $debtDebtorId,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'exchange_rate_to_usd' => $transaction->exchange_rate_to_usd,
                    'date' => $transaction->occurred_at->toDateString(),
                    'status' => DebtStatus::Outstanding,
                    'recorded_by_id' => $transaction->user_id,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction recorded.')]);

        return to_route('transactions.index');
    }

    public function edit(Transaction $transaction): Response|RedirectResponse
    {
        $this->authorize('update', $transaction);

        if ($transaction->managedBy() !== null) {
            return $this->managedElsewhere($transaction);
        }

        $transaction->loadMissing('debt');

        return Inertia::render('transactions/edit', [
            'transaction' => [
                ...$transaction->only([
                    'id', 'type', 'fuel_type_id', 'tank_id', 'pump_id', 'liters', 'price_per_liter',
                    'description', 'amount', 'currency', 'to_currency', 'to_amount', 'exchange_rate_to_usd',
                    'occurred_at', 'notes', 'other_income_category',
                ]),
                'debt' => $transaction->debt?->only(['debtor_id', 'direction']),
                'original' => $transaction->originalPayment(),
            ],
            'tanks' => $this->tankOptions(),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'exchangeRates' => collect(Currency::codes())->mapWithKeys(
                fn (string $currency) => [$currency => ExchangeRate::currentRateFor($currency)]
            ),
            ...app(CashBox::class)->formProps(),
        ]);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        if ($transaction->managedBy() !== null) {
            return $this->managedElsewhere($transaction);
        }

        $data = $request->validated();

        // Only re-time the transaction when the date actually changes to a different day —
        // otherwise resubmitting the same date on every edit would keep bumping it to "now"
        // and lose the original time-of-day.
        if (isset($data['occurred_at'])) {
            $newDate = Carbon::parse($data['occurred_at'])->toDateString();
            $data['occurred_at'] = $newDate === $transaction->occurred_at->toDateString()
                ? $transaction->occurred_at
                : Carbon::parse($data['occurred_at'])->setTimeFrom(now());
        }

        $data = $this->withFuelFields($data);

        // Only a currency exchange carries a rate -- the one entered for that exchange. No other
        // record uses or stores one (amounts always stay in their own currency).
        if ($data['type'] !== TransactionType::CurrencyExchange->value) {
            $data['exchange_rate_to_usd'] = null;
        }

        $markAsDebt = ($data['mark_as_debt'] ?? false) && $data['type'] !== TransactionType::CurrencyExchange->value;
        $debtDebtorId = $data['debt_debtor_id'] ?? null;
        $debtDirection = $data['debt_direction'] ?? DebtDirection::Receivable->value;
        unset($data['mark_as_debt'], $data['debt_debtor_id'], $data['debt_direction']);
        $data = $this->withCashSource($request, $data, $markAsDebt, $this->takenOutBy($transaction));

        DB::transaction(function () use ($transaction, $data, $markAsDebt, $debtDebtorId, $debtDirection) {
            $transaction->update($data);

            $existingDebt = $transaction->debt;

            if ($markAsDebt) {
                $payload = [
                    'direction' => $debtDirection,
                    'debtor_id' => $debtDebtorId,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'exchange_rate_to_usd' => $transaction->exchange_rate_to_usd,
                    'date' => $transaction->occurred_at->toDateString(),
                ];

                if ($existingDebt) {
                    $existingDebt->update($payload);
                } else {
                    $transaction->debt()->create($payload + [
                        'status' => DebtStatus::Outstanding,
                        'recorded_by_id' => $transaction->user_id,
                    ]);
                }
            } elseif ($existingDebt) {
                $existingDebt->delete();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction updated.')]);

        return to_route('transactions.index');
    }

    /**
     * Only a fuel delivery is about a tank and its fuel: every other type is saved without a
     * tank, fuel type, liters or price per liter (the form always sends the selected tank,
     * which used to give expenses that tank's fuel type).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withFuelFields(array $data): array
    {
        if ($data['type'] !== TransactionType::FuelDelivery->value) {
            return [...$data, 'tank_id' => null, 'fuel_type_id' => null, 'liters' => null, 'price_per_liter' => null];
        }

        return [...$data, 'fuel_type_id' => Tank::find((int) $data['tank_id'])?->fuel_type_id];
    }

    /**
     * An expense or purchase paid now takes money out of a box (one recorded as a debt doesn't,
     * until it's paid): checked, and possibly paid from the primary box at a rate -- then it's
     * stored in the primary currency, with what it was for. A currency exchange gives up the
     * currency it exchanges, so that box is checked too. See CashBox.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, float>  $giveBack
     * @return array<string, mixed>
     */
    private function withCashSource(Request $request, array $data, bool $markAsDebt, array $giveBack = []): array
    {
        unset($data['pay_from'], $data['cash_rate'], $data[CashBox::CONFIRM_FIELD]);
        $data = [...$data, 'original_amount' => null, 'original_currency' => null, 'original_rate' => null];
        $type = $data['type'];

        if (! $markAsDebt && in_array($type, [TransactionType::Expense->value, TransactionType::Purchase->value], true)) {
            return [...$data, ...$this->cashOut($request, (float) $data['amount'], (string) $data['currency'], $giveBack)];
        }

        if ($type === TransactionType::CurrencyExchange->value) {
            $this->assertCashFor($request, [(string) $data['currency'] => (float) $data['amount']], $giveBack);
        }

        return $data;
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $this->authorize('delete', $transaction);

        // Shop entries are deleted from the Shop's own log through this route; fuel sales and
        // Sadcop entries are tied to a reading or ledger entry and are removed from there.
        if (! in_array($transaction->managedBy(), [null, 'shop'], true)) {
            return $this->managedElsewhere($transaction);
        }

        $transaction->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction deleted.')]);

        // This route is shared by more than just the Transactions page -- Shop's sale/purchase
        // log deletes through it too -- so a hardcoded to_route('transactions.index') always
        // bounced a Shop deletion over to /transactions. back() returns to wherever the delete
        // request actually came from (Inertia sets Referer to the current page), which also
        // preserves any active filters/pagination on the Transactions page itself rather than
        // resetting them.
        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tankOptions(): array
    {
        return Tank::with('fuelType')
            ->orderBy('fuel_type_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Tank $tank) => [
                'id' => $tank->id,
                'name' => $tank->name,
                'fuel_type_id' => $tank->fuel_type_id,
                'fuel_type_name' => $tank->fuelType->name,
                'is_active' => $tank->is_active,
                'currentPrice' => $tank->fuelType->currentPrice()?->only(['price_per_liter', 'currency']),
                'remaining_liters' => round($tank->remainingCapacity(), 3),
            ])
            ->all();
    }

    /** A transaction recorded by another screen is corrected there, so its records stay in step. */
    private function managedElsewhere(Transaction $transaction): RedirectResponse
    {
        $screen = match ($transaction->managedBy()) {
            'pump_counters' => __('nav.pump_counters'),
            'shop' => __('nav.shop'),
            default => __('nav.sadcop'),
        };

        Inertia::flash('toast', ['type' => 'error', 'message' => __('This entry was recorded in :screen. Change or delete it there.', ['screen' => $screen])]);

        return back(303);
    }
}
