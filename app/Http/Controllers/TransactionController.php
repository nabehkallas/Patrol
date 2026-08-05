<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\FuelPump;
use App\Models\PumpCounterReading;
use App\Models\Tank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PdfTableExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = $this->filteredQuery($request);

        return Inertia::render('transactions/index', [
            'transactions' => $query->paginate(25)->withQueryString(),
            'users' => $user->isAdmin() ? User::orderBy('name')->get(['id', 'name']) : [],
            'filters' => $request->only(['type', 'user_id']),
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Transaction::with(['user', 'fuelType', 'tank', 'debt.debtor'])->latest('occurred_at');

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
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
        $transactions = $this->filteredQuery($request)->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'المعاملات',
            'date' => 'التاريخ',
            'type' => 'النوع',
            'description' => 'الوصف',
            'liters' => 'اللترات',
            'amount' => 'المبلغ',
            'recorded_by' => 'سجّله',
        ] : [
            'title' => 'Transactions',
            'date' => 'Date',
            'type' => 'Type',
            'description' => 'Description',
            'liters' => 'Liters',
            'amount' => 'Amount',
            'recorded_by' => 'Recorded by',
        ];

        $rows = $transactions->map(fn (Transaction $transaction) => [
            $transaction->occurred_at->format('Y-m-d H:i'),
            $transaction->type->value,
            $transaction->description ?? $transaction->fuelType?->name ?? '—',
            $transaction->liters !== null ? number_format((float) $transaction->liters, 3) : '—',
            number_format((float) $transaction->amount, 1).' '.$transaction->currency->value,
            $transaction->user?->name ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'transactions-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: null,
            headers: [$labels['date'], $labels['type'], $labels['description'], $labels['liters'], $labels['amount'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    public function create(): Response
    {
        return Inertia::render('transactions/create', [
            'tanks' => $this->tankOptions(),
            'pumps' => $this->pumpOptions(),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'exchangeRates' => collect(Currency::cases())->mapWithKeys(
                fn (Currency $currency) => [$currency->value => ExchangeRate::currentRateFor($currency)]
            ),
        ]);
    }

    public function store(StoreTransactionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $data['occurred_at'] ??= now();

        if (! empty($data['tank_id'])) {
            $data['fuel_type_id'] = Tank::find($data['tank_id'])?->fuel_type_id;
        }

        if (empty($data['exchange_rate_to_usd'])) {
            $data['exchange_rate_to_usd'] = ExchangeRate::currentRateFor(Currency::from($data['currency']));
        }

        $markAsDebt = $data['mark_as_debt'] ?? false;
        $debtDebtorId = $data['debt_debtor_id'] ?? null;
        unset($data['mark_as_debt'], $data['debt_debtor_id']);

        DB::transaction(function () use ($data, $markAsDebt, $debtDebtorId) {
            $transaction = Transaction::create($data);

            if ($markAsDebt) {
                $transaction->debt()->create([
                    'debtor_id' => $debtDebtorId,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'exchange_rate_to_usd' => $transaction->exchange_rate_to_usd,
                    'date' => $transaction->occurred_at->toDateString(),
                    'status' => DebtStatus::Outstanding,
                    'recorded_by_id' => $transaction->user_id,
                ]);
            }

            $this->syncPumpCounterReading($transaction);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction recorded.')]);

        return to_route('transactions.index');
    }

    public function edit(Transaction $transaction): Response
    {
        $this->authorize('update', $transaction);

        $transaction->loadMissing('debt');

        return Inertia::render('transactions/edit', [
            'transaction' => [
                ...$transaction->only([
                    'id', 'type', 'fuel_type_id', 'tank_id', 'pump_id', 'liters', 'price_per_liter',
                    'description', 'amount', 'currency', 'exchange_rate_to_usd',
                    'occurred_at', 'notes',
                ]),
                'debt' => $transaction->debt?->only(['debtor_id']),
            ],
            'tanks' => $this->tankOptions(),
            'pumps' => $this->pumpOptions(),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'exchangeRates' => collect(Currency::cases())->mapWithKeys(
                fn (Currency $currency) => [$currency->value => ExchangeRate::currentRateFor($currency)]
            ),
        ]);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();

        if (! empty($data['tank_id'])) {
            $data['fuel_type_id'] = Tank::find($data['tank_id'])?->fuel_type_id;
        }

        $markAsDebt = $data['mark_as_debt'] ?? false;
        $debtDebtorId = $data['debt_debtor_id'] ?? null;
        unset($data['mark_as_debt'], $data['debt_debtor_id']);

        DB::transaction(function () use ($transaction, $data, $markAsDebt, $debtDebtorId) {
            $transaction->update($data);

            $existingDebt = $transaction->debt;

            if ($markAsDebt) {
                $payload = [
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

            $this->syncPumpCounterReading($transaction);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction updated.')]);

        return to_route('transactions.index');
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $this->authorize('delete', $transaction);

        $transaction->pumpCounterReading()->delete();
        $transaction->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction deleted.')]);

        return to_route('transactions.index');
    }

    /**
     * Keeps a fuel_sale transaction's pump counter in sync: a sale through a pump really did
     * advance that pump's physical meter, so we record it as a PumpCounterReading (not a
     * separate tally) — this way Pump Counters' daily totals/history pick it up automatically,
     * and the next real meter reading's diff won't double-count these liters.
     */
    private function syncPumpCounterReading(Transaction $transaction): void
    {
        $existing = $transaction->pumpCounterReading;

        if ($transaction->type !== TransactionType::FuelSale || $transaction->pump_id === null) {
            $existing?->delete();

            return;
        }

        $baselineQuery = PumpCounterReading::where('pump_id', $transaction->pump_id)->latest('id');

        if ($existing) {
            $baselineQuery->where('id', '!=', $existing->id);
        }

        $baseline = (float) ($baselineQuery->first()?->reading_value ?? 0);
        $liters = (float) $transaction->liters;

        $existing?->delete();

        PumpCounterReading::create([
            'pump_id' => $transaction->pump_id,
            'tank_id' => $transaction->tank_id,
            'date' => $transaction->occurred_at->toDateString(),
            'reading_value' => round($baseline + $liters, 3),
            'liters_sold' => round($liters, 3),
            'transaction_id' => $transaction->id,
            'recorded_by_id' => $transaction->user_id,
            'notes' => __('Recorded from Transactions'),
        ]);
    }

    private function pumpOptions()
    {
        return FuelPump::orderBy('name')->get(['id', 'name', 'fuel_type_id']);
    }

    private function tankOptions()
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
                'currentPrice' => $tank->fuelType->currentPrice()?->only(['price_per_liter', 'currency']),
                'remaining_liters' => round($tank->remainingCapacity(), 3),
            ]);
    }
}
