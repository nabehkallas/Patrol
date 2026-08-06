<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Http\Requests\StoreDebtorRequest;
use App\Http\Requests\UpdateDebtorRequest;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Services\PdfTableExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DebtorController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->filteredQuery($request);

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        $debtors = $query->orderBy('name')->paginate(25)->withQueryString();

        $debtors->getCollection()->transform(fn (Debtor $debtor) => [
            ...$debtor->only(['id', 'name', 'phone']),
            'outstanding_syp' => $this->outstandingTotal($debtor, $sypRate),
        ]);

        return Inertia::render('debtors/index', [
            'debtors' => $debtors,
            'filters' => $request->only(['search']),
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Debtor::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        return $query;
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        $debtors = $this->filteredQuery($request)->orderBy('name')->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'المدينون',
            'name' => 'الاسم',
            'phone' => 'الهاتف',
            'outstanding' => 'غير مسدد',
        ] : [
            'title' => 'Debtors',
            'name' => 'Name',
            'phone' => 'Phone',
            'outstanding' => 'Outstanding',
        ];

        $rows = $debtors->map(fn (Debtor $debtor) => [
            $debtor->name,
            $debtor->phone ?? '—',
            number_format($this->outstandingTotal($debtor, $sypRate), 0).' SYP',
        ])->all();

        return $exporter->download(
            filename: 'debtors-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: null,
            headers: [$labels['name'], $labels['phone'], $labels['outstanding']],
            rows: $rows,
            direction: $direction,
        );
    }

    /**
     * Money owed *to the station* by this party — payable debts (where the station owes
     * them instead) are tracked separately and excluded here, matching the Debts page.
     */
    private function outstandingTotal(Debtor $debtor, float $sypRate): float
    {
        return round(
            $debtor->debts()
                ->where('status', DebtStatus::Outstanding)
                ->where('direction', DebtDirection::Receivable)
                ->get()
                ->sum(fn (Debt $debt) => $debt->amountInSyp($sypRate)),
            0
        );
    }

    public function create(): Response
    {
        return Inertia::render('debtors/create');
    }

    public function store(StoreDebtorRequest $request): RedirectResponse
    {
        Debtor::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debtor added.')]);

        return to_route('debtors.index');
    }

    public function edit(Debtor $debtor): Response
    {
        return Inertia::render('debtors/edit', [
            'debtor' => $debtor->only(['id', 'name', 'phone']),
        ]);
    }

    public function update(UpdateDebtorRequest $request, Debtor $debtor): RedirectResponse
    {
        $debtor->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debtor updated.')]);

        return to_route('debtors.index');
    }

    public function destroy(Debtor $debtor): RedirectResponse
    {
        if ($debtor->debts()->exists()) {
            return back()->withErrors(['debtor' => __('This debtor has debt history and cannot be deleted.')]);
        }

        $debtor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debtor deleted.')]);

        return to_route('debtors.index');
    }

    public function settleAll(Debtor $debtor): RedirectResponse
    {
        $debtor->debts()
            ->where('status', DebtStatus::Outstanding)
            ->update(['status' => DebtStatus::Settled, 'settled_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All debts settled.')]);

        return to_route('debtors.index');
    }
}
