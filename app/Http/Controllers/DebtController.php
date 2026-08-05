<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Http\Requests\StoreDebtRequest;
use App\Http\Requests\UpdateDebtRequest;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\FuelType;
use App\Services\PdfTableExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DebtController extends Controller
{
    use GroupsByCurrency;

    public function index(Request $request): Response
    {
        $query = $this->filteredQuery($request);

        return Inertia::render('debts/index', [
            'debts' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['status', 'sort', 'sort_dir', 'debtor_id']),
            'debtors' => Debtor::orderBy('name')->get(['id', 'name']),
            'totals' => [
                'outstanding' => $this->outstandingTotal($request),
                'total' => $this->allDebtsTotal($request),
            ],
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Debt::with(['recordedBy', 'debtor', 'fuelType', 'transaction.fuelType']);

        if ($request->filled('debtor_id')) {
            $query->where('debtor_id', $request->integer('debtor_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
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
            'status' => 'الحالة',
            'recorded_by' => 'سجّله',
            'outstanding' => 'غير مسدد',
            'settled' => 'مسدد',
            'total' => 'الإجمالي',
        ] : [
            'title' => 'Debts',
            'date' => 'Date',
            'debtor' => 'Debtor',
            'what_for' => 'What for',
            'amount' => 'Amount',
            'status' => 'Status',
            'recorded_by' => 'Recorded by',
            'outstanding' => 'Outstanding',
            'settled' => 'Settled',
            'total' => 'Total',
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
                $debt->status === DebtStatus::Outstanding ? $labels['outstanding'] : $labels['settled'],
                $debt->recordedBy?->name ?? '—',
            ];
        })->all();

        $totalBreakdown = collect($this->allDebtsTotal($request))
            ->map(fn ($amount, $currency) => number_format($amount, $currency === 'SYP' ? 0 : 2).' '.$currency)
            ->implode(' + ');

        return $exporter->download(
            filename: 'debts-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: $labels['total'].': '.$totalBreakdown,
            headers: [$labels['date'], $labels['debtor'], $labels['what_for'], $labels['amount'], $labels['status'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    private function outstandingTotal(Request $request): array
    {
        $query = Debt::where('status', DebtStatus::Outstanding);

        if ($request->filled('debtor_id')) {
            $query->where('debtor_id', $request->integer('debtor_id'));
        }

        return $this->byCurrency($query->get());
    }

    private function allDebtsTotal(Request $request): array
    {
        $query = Debt::query();

        if ($request->filled('debtor_id')) {
            $query->where('debtor_id', $request->integer('debtor_id'));
        }

        return $this->byCurrency($query->get());
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

        Debt::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt recorded.')]);

        return to_route('debts.index');
    }

    public function edit(Debt $debt): Response
    {
        $this->authorize('update', $debt);

        return Inertia::render('debts/edit', [
            'debt' => $debt->only([
                'id', 'debtor_id', 'fuel_type_id', 'liters', 'price_per_liter', 'amount', 'currency',
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

    public function settle(Debt $debt): RedirectResponse
    {
        $this->authorize('settle', $debt);

        if ($debt->status !== DebtStatus::Settled) {
            $debt->update([
                'status' => DebtStatus::Settled,
                'settled_at' => now(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debt settled.')]);

        return to_route('debts.index');
    }
}
