<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\SadcopLedgerEntryType;
use App\Enums\TransactionType;
use App\Http\Requests\StoreSadcopDeliveryRequest;
use App\Http\Requests\StoreSadcopDepositRequest;
use App\Http\Requests\StoreSadcopOpeningBalanceRequest;
use App\Http\Requests\UpdateSadcopEntryRequest;
use App\Models\ExchangeRate;
use App\Models\FuelType;
use App\Models\SadcopLedgerEntry;
use App\Models\Tank;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SadcopController extends Controller
{
    public function index(Request $request): Response
    {
        $needsOpeningBalance = SadcopLedgerEntry::query()->doesntExist();

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $query = $this->filteredEntriesQuery($request, $from, $to);

        return Inertia::render('sadcop/index', [
            'entries' => $query->paginate(25)->withQueryString(),
            'filters' => [
                ...$request->only(['type', 'fuel_type_id']),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'fuelTypes' => FuelType::orderBy('name')->get(['id', 'name']),
            'balance' => round(SadcopLedgerEntry::currentBalanceSyp(), 0),
            'monthPayments' => $this->sadcopPaymentsTotal(now()->startOfMonth(), now()->endOfDay()),
            'needsOpeningBalance' => $needsOpeningBalance,
        ]);
    }

    private function filteredEntriesQuery(Request $request, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $query = SadcopLedgerEntry::with(['transaction.tank.fuelType', 'recordedBy'])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->latest('occurred_at');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('fuel_type_id')) {
            $query->whereHas('transaction', fn (Builder $q) => $q->where('fuel_type_id', $request->integer('fuel_type_id')));
        }

        return $query;
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $entries = $this->filteredEntriesQuery($request, $from, $to)->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'سجل سادكوب',
            'date' => 'التاريخ',
            'type' => 'النوع',
            'fuel_type' => 'نوع الوقود',
            'liters' => 'اللترات',
            'price' => 'سعر تكلفة سادكوب / لتر',
            'amount' => 'المبلغ',
            'recorded_by' => 'سجّله',
            'types' => ['opening' => 'الرصيد الافتتاحي', 'deposit' => 'تحويل', 'delivery' => 'توريد'],
        ] : [
            'title' => 'Sadcop Ledger',
            'date' => 'Date',
            'type' => 'Type',
            'fuel_type' => 'Fuel type',
            'liters' => 'Liters',
            'price' => 'Sadcop cost price / liter',
            'amount' => 'Amount',
            'recorded_by' => 'Recorded by',
            'types' => ['opening' => 'Opening balance', 'deposit' => 'Transfer', 'delivery' => 'Delivery'],
        ];

        $rows = $entries->map(fn (SadcopLedgerEntry $entry) => [
            $entry->occurred_at->format('Y-m-d H:i'),
            $labels['types'][$entry->type->value] ?? $entry->type->value,
            $entry->transaction?->tank?->fuelType?->name ?? '—',
            $entry->liters !== null ? number_format((float) $entry->liters, 3) : '—',
            $entry->price_per_liter !== null ? number_format((float) $entry->price_per_liter, 2) : '—',
            ($entry->type === SadcopLedgerEntryType::Delivery ? '-' : '+').number_format((float) $entry->amount, 1).' SYP',
            $entry->recordedBy?->name ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'sadcop-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: [$labels['date'], $labels['type'], $labels['fuel_type'], $labels['liters'], $labels['price'], $labels['amount'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    /**
     * Total cash paid into Sadcop's balance (transfers) within the given range. Station-wide,
     * same as the balance itself — not scoped to the viewing user.
     */
    private function sadcopPaymentsTotal(CarbonInterface $from, CarbonInterface $to): float
    {
        return round(
            (float) SadcopLedgerEntry::query()
                ->where('type', SadcopLedgerEntryType::Deposit)
                ->where('occurred_at', '>=', $from)
                ->where('occurred_at', '<=', $to)
                ->sum('amount'),
            0
        );
    }

    public function storeOpeningBalance(StoreSadcopOpeningBalanceRequest $request): RedirectResponse
    {
        if (SadcopLedgerEntry::query()->exists()) {
            return to_route('sadcop.index');
        }

        $data = $request->validated();

        SadcopLedgerEntry::create([
            'type' => SadcopLedgerEntryType::Opening,
            'amount' => $data['amount'],
            'recorded_by_id' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Opening balance set.')]);

        return to_route('sadcop.index');
    }

    public function createDeposit(): Response
    {
        return Inertia::render('sadcop/deposit-create');
    }

    public function storeDeposit(StoreSadcopDepositRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['occurred_at'] ??= now();

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        DB::transaction(function () use ($request, $data, $sypRate) {
            $transaction = Transaction::create([
                'user_id' => $request->user()->id,
                'type' => TransactionType::Expense,
                'description' => 'Sadcop balance transfer',
                'amount' => $data['amount'],
                'currency' => Currency::SYP,
                'exchange_rate_to_usd' => $sypRate,
                'occurred_at' => $data['occurred_at'],
                'notes' => $data['notes'] ?? null,
            ]);

            $transaction->sadcopLedgerEntry()->create([
                'type' => SadcopLedgerEntryType::Deposit,
                'amount' => $data['amount'],
                'recorded_by_id' => $request->user()->id,
                'occurred_at' => $data['occurred_at'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Money transferred to Sadcop.')]);

        return to_route('sadcop.index');
    }

    public function createDelivery(): Response
    {
        return Inertia::render('sadcop/delivery-create', [
            'tanks' => $this->tankOptions(),
            'balance' => round(SadcopLedgerEntry::currentBalanceSyp(), 0),
        ]);
    }

    /**
     * Sadcop's cost price for a liter of fuel: the current selling price minus the
     * configured profit margin — mirrors the calculation in Admin\EarningsController.
     */
    private function defaultCostPricePerLiter(Tank $tank, float $sypRate): float
    {
        $currentPrice = $tank->fuelType->currentPrice();
        $priceSyp = $currentPrice ? $currentPrice->amountInSyp($sypRate) : 0.0;
        $marginPercent = (float) ($tank->fuelType->profit_margin_percent ?? 0);

        return round($priceSyp * (1 - $marginPercent / 100), 2);
    }

    public function storeDelivery(StoreSadcopDeliveryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        // A plain date picked in the form keeps today's time-of-day rather than collapsing to
        // midnight, so same-day entries still sort in the order they were actually recorded.
        $data['occurred_at'] = isset($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])->setTimeFrom(now())
            : now();

        if ((float) $data['amount'] > SadcopLedgerEntry::currentBalanceSyp() + 0.01) {
            return back()->withErrors(['amount' => __('This exceeds the current Sadcop balance.')])->withInput();
        }

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        DB::transaction(function () use ($request, $data, $sypRate) {
            $transaction = Transaction::create([
                'user_id' => $request->user()->id,
                'type' => TransactionType::FuelDelivery,
                'tank_id' => $data['tank_id'],
                'fuel_type_id' => Tank::find($data['tank_id'])?->fuel_type_id,
                'liters' => $data['liters'],
                'price_per_liter' => $data['price_per_liter'],
                'amount' => $data['amount'],
                'currency' => Currency::SYP,
                'exchange_rate_to_usd' => $sypRate,
                'occurred_at' => $data['occurred_at'],
                'notes' => $data['notes'] ?? null,
                'paid_by_sadcop' => true,
            ]);

            $transaction->sadcopLedgerEntry()->create([
                'type' => SadcopLedgerEntryType::Delivery,
                'amount' => $data['amount'],
                'liters' => $data['liters'],
                'price_per_liter' => $data['price_per_liter'],
                'recorded_by_id' => $request->user()->id,
                'occurred_at' => $data['occurred_at'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery recorded.')]);

        return to_route('sadcop.index');
    }

    public function editEntry(SadcopLedgerEntry $entry): Response
    {
        $entry->loadMissing('transaction');

        return Inertia::render('sadcop/entry-edit', [
            'entry' => [
                'id' => $entry->id,
                'type' => $entry->type,
                'amount' => $entry->amount,
                'liters' => $entry->liters,
                'price_per_liter' => $entry->price_per_liter,
                'occurred_at' => $entry->occurred_at,
                'notes' => $entry->notes,
                'tank_id' => $entry->transaction?->tank_id,
            ],
            'tanks' => $this->tankOptions(),
            'balance' => round(SadcopLedgerEntry::currentBalanceSyp(), 0),
        ]);
    }

    public function updateEntry(UpdateSadcopEntryRequest $request, SadcopLedgerEntry $entry): RedirectResponse
    {
        $data = $request->validated();

        if ($entry->type === SadcopLedgerEntryType::Delivery) {
            $balanceExcludingThis = SadcopLedgerEntry::currentBalanceSyp() + (float) $entry->amount;

            if ((float) $data['amount'] > $balanceExcludingThis + 0.01) {
                return back()->withErrors(['amount' => __('This exceeds the current Sadcop balance.')])->withInput();
            }
        }

        DB::transaction(function () use ($entry, $data) {
            match ($entry->type) {
                SadcopLedgerEntryType::Opening => $entry->update([
                    'amount' => $data['amount'],
                    'notes' => $data['notes'] ?? null,
                ]),
                SadcopLedgerEntryType::Deposit => $this->applyDepositUpdate($entry, $data),
                SadcopLedgerEntryType::Delivery => $this->applyDeliveryUpdate($entry, $data),
            };
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry updated.')]);

        return to_route('sadcop.index');
    }

    private function applyDepositUpdate(SadcopLedgerEntry $entry, array $data): void
    {
        $payload = [
            'amount' => $data['amount'],
            'occurred_at' => $data['occurred_at'] ?? $entry->occurred_at,
            'notes' => $data['notes'] ?? null,
        ];

        $entry->transaction?->update($payload);
        $entry->update($payload);
    }

    private function applyDeliveryUpdate(SadcopLedgerEntry $entry, array $data): void
    {
        $tank = Tank::find($data['tank_id']);
        $occurredAt = $data['occurred_at'] ?? $entry->occurred_at;

        $entry->transaction?->update([
            'tank_id' => $data['tank_id'],
            'fuel_type_id' => $tank?->fuel_type_id,
            'liters' => $data['liters'],
            'price_per_liter' => $data['price_per_liter'],
            'amount' => $data['amount'],
            'occurred_at' => $occurredAt,
            'notes' => $data['notes'] ?? null,
        ]);

        $entry->update([
            'liters' => $data['liters'],
            'price_per_liter' => $data['price_per_liter'],
            'amount' => $data['amount'],
            'occurred_at' => $occurredAt,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function destroyEntry(SadcopLedgerEntry $entry): RedirectResponse
    {
        DB::transaction(function () use ($entry) {
            if ($entry->transaction) {
                // Cascades to delete the ledger entry itself (transactions.id is
                // sadcop_ledger_entries.transaction_id, cascadeOnDelete).
                $entry->transaction->delete();
            } else {
                $entry->delete();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry deleted.')]);

        return to_route('sadcop.index');
    }

    private function tankOptions()
    {
        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        return Tank::with('fuelType')
            ->orderBy('fuel_type_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Tank $tank) => [
                'id' => $tank->id,
                'name' => $tank->name,
                'fuel_type_id' => $tank->fuel_type_id,
                'fuel_type_name' => $tank->fuelType->name,
                'remaining_liters' => round($tank->remainingCapacity(), 3),
                'default_cost_price_per_liter' => $this->defaultCostPricePerLiter($tank, $sypRate),
            ]);
    }
}
