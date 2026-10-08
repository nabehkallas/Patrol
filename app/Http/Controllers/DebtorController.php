<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Http\Requests\StoreDebtorRequest;
use App\Http\Requests\UpdateDebtorRequest;
use App\Models\Debt;
use App\Models\Debtor;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use App\Support\Currency;
use App\Support\ExportTable;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DebtorController extends Controller
{
    use GroupsByCurrency;

    public function index(Request $request): Response
    {
        $searching = $request->filled('search');

        // Searching flattens the hierarchy — a matching sub-debtor should surface even if its
        // parent's name doesn't match — so each row carries its own parent's name for context
        // instead of being nested under it. Without a search, only top-level debtors paginate;
        // each one's sub-debtors are eager-loaded alongside it (not counted against the page
        // size) so a parent and its children always stay together on the same page.
        $query = $this->filteredQuery($request);

        if (! $searching) {
            $query->whereNull('parent_id')->with('children');
        }

        $debtors = $query->orderBy('name')->paginate(25)->withQueryString();

        $debtors = $debtors->through(function (Debtor $debtor) use ($searching) {
            $own = $this->outstandingTotal($debtor);

            if ($searching) {
                return [
                    ...$debtor->only(['id', 'name', 'phone', 'parent_id']),
                    'outstanding' => $own,
                    'parent_name' => $debtor->parent?->name,
                ];
            }

            $children = $debtor->children->map(fn (Debtor $child) => [
                ...$child->only(['id', 'name', 'phone', 'parent_id']),
                'outstanding' => $this->outstandingTotal($child),
            ])->all();

            return [
                ...$debtor->only(['id', 'name', 'phone', 'parent_id']),
                'outstanding' => $this->combineBreakdowns($own, ...array_column($children, 'outstanding')),
                'children' => $children,
            ];
        });

        return Inertia::render('debtors/index', [
            'debtors' => $debtors,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * @param  array<string, float>  $breakdowns
     * @return array<string, float>
     */
    private function combineBreakdowns(array ...$breakdowns): array
    {
        $result = ['SYP' => 0.0];

        foreach ($breakdowns as $breakdown) {
            foreach ($breakdown as $currency => $amount) {
                $result[$currency] = ($result[$currency] ?? 0.0) + $amount;
            }
        }

        return $result;
    }

    /**
     * @return Builder<Debtor>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Debtor::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%')
                ->with('parent');
        }

        return $query;
    }

    /**
     * @return Collection<int, Debtor>
     */
    private function parentOptions(?int $excludeId = null): Collection
    {
        return Debtor::whereNull('parent_id')
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        return $this->exportTable($request)->pdf($exporter);
    }

    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        return $this->exportTable($request)->xlsx($exporter);
    }

    /** Each debtor with what they owe: one row per currency they owe in (or one zero row). */
    private function exportTable(Request $request): ExportTable
    {
        $labels = Locales::labels([
            'title' => 'Debtors',
            'name' => 'Name',
            'phone' => 'Phone',
            'outstanding' => 'Outstanding',
            'currency' => 'common.currency',
        ]);

        $rows = [];
        foreach ($this->filteredQuery($request)->orderBy('name')->get() as $debtor) {
            $owed = collect($this->outstandingTotal($debtor))->filter(fn ($amount) => abs((float) $amount) > 0.004);

            if ($owed->isEmpty()) {
                $rows[] = [$debtor->name, $debtor->phone, 0.0, Currency::primary()];

                continue;
            }

            foreach ($owed as $currency => $amount) {
                $rows[] = [$debtor->name, $debtor->phone, (float) $amount, (string) $currency];
            }
        }

        return new ExportTable(
            name: 'debtors',
            title: $labels['title'],
            subtitle: null,
            headers: [$labels['name'], $labels['phone'], $labels['outstanding'], $labels['currency']],
            rows: $rows,
            decimals: [2 => fn (array $row) => Currency::decimals((string) $row[3])],
        );
    }

    /**
     * Money owed *to the station* by this party — payable debts (where the station owes
     * them instead) are tracked separately and excluded here, matching the Debts page.
     *
     * @return array<string, float>
     */
    private function outstandingTotal(Debtor $debtor): array
    {
        return $this->byCurrency(
            $debtor->debts()
                ->where('status', DebtStatus::Outstanding)
                ->where('direction', DebtDirection::Receivable)
                ->with('payments')
                ->get(),
            fn (Debt $debt) => $debt->remainingAmount(),
        );
    }

    public function create(): Response
    {
        return Inertia::render('debtors/create', [
            'parents' => $this->parentOptions(),
        ]);
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
            'debtor' => $debtor->only(['id', 'name', 'phone', 'parent_id']),
            'parents' => $this->parentOptions($debtor->id),
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

        if ($debtor->children()->exists()) {
            return back()->withErrors(['debtor' => __('This debtor has sub-debtors and cannot be deleted.')]);
        }

        $debtor->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Debtor deleted.')]);

        return to_route('debtors.index');
    }

    public function settleAll(Request $request, Debtor $debtor): RedirectResponse
    {
        $debtor->debts()
            ->where('status', DebtStatus::Outstanding)
            ->with('payments')
            ->get()
            ->each(fn (Debt $debt) => $debt->recordPayment($debt->remainingAmount(), $request->user()->id));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All debts settled.')]);

        return to_route('debtors.index');
    }
}
