<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\FuelPump;
use App\Models\PumpCounterReading;
use App\Models\Tank;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PumpCounterReadingController extends Controller
{
    public function index(Request $request): Response
    {
        $date = Carbon::parse($request->input('date', today()->toDateString()));

        $pumps = FuelPump::with('fuelTypes')->orderBy('name')->get()
            ->map(function (FuelPump $pump) use ($date) {
                $latest = $pump->counterReadings()->latest('id')->first();
                $dailyLiters = (float) $pump->counterReadings()
                    ->whereDate('date', $date)
                    ->sum('liters_sold');

                return [
                    'id' => $pump->id,
                    'name' => $pump->name,
                    'fuel_type_ids' => $pump->fuelTypes->pluck('id'),
                    'fuel_type_names' => $pump->fuelTypes->pluck('name'),
                    'daily_liters_sold' => round($dailyLiters, 3),
                    'latest_reading' => $latest ? [
                        'date' => $latest->date->toDateString(),
                        'reading_value' => $latest->reading_value,
                        'tank_id' => $latest->tank_id,
                    ] : null,
                ];
            });

        $readings = PumpCounterReading::with(['pump', 'tank.fuelType', 'recordedBy'])
            ->whereDate('date', $date)
            ->latest('id')
            ->get();

        // Since a pump's meter can now be shared across several fuel types, "liters sold today
        // per fuel type" can no longer be read off the pump — it's derived from each reading's
        // own tank (which is what actually determines the fuel type of that sale).
        $fuelTypeTotals = $readings
            ->filter(fn (PumpCounterReading $reading) => $reading->liters_sold !== null && $reading->tank !== null)
            ->groupBy(fn (PumpCounterReading $reading) => $reading->tank->fuel_type_id)
            ->map(fn ($group) => [
                'fuel_type_id' => $group->first()->tank->fuel_type_id,
                'fuel_type_name' => $group->first()->tank->fuelType?->name,
                'liters_sold' => round((float) $group->sum('liters_sold'), 3),
            ])
            ->sortBy('fuel_type_name')
            ->values();

        return Inertia::render('pump-counters/index', [
            'pumps' => $pumps,
            'tanks' => $this->tankOptions(),
            'readings' => $readings,
            'fuelTypeTotals' => $fuelTypeTotals,
            'date' => $date->toDateString(),
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $date = Carbon::parse($request->input('date', today()->toDateString()));
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $readings = PumpCounterReading::with(['pump', 'tank.fuelType', 'recordedBy'])
            ->whereDate('date', $date)
            ->latest('id')
            ->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'قراءات المضخات',
            'pump' => 'المضخة',
            'tank' => 'الخزان',
            'reading' => 'قيمة العداد',
            'liters_sold' => 'اللترات المباعة',
            'governmental' => 'مبيعات حكومية (لتر)',
            'return' => 'مرتجع (لتر)',
            'recorded_by' => 'سجّله',
        ] : [
            'title' => 'Pump Counter Readings',
            'pump' => 'Pump',
            'tank' => 'Tank',
            'reading' => 'Counter value',
            'liters_sold' => 'Liters sold',
            'governmental' => 'Governmental sale (L)',
            'return' => 'Return (L)',
            'recorded_by' => 'Recorded by',
        ];

        $rows = $readings->map(fn (PumpCounterReading $reading) => [
            $reading->pump?->name ?? '—',
            $reading->tank ? $reading->tank->fuelType?->name.' — '.$reading->tank->name : '—',
            number_format((float) $reading->reading_value, 0),
            $reading->liters_sold !== null ? number_format((float) $reading->liters_sold, 3).' L' : '—',
            $reading->governmental_liters !== null ? number_format((float) $reading->governmental_liters, 3).' L' : '—',
            $reading->return_liters !== null ? number_format((float) $reading->return_liters, 3).' L' : '—',
            $reading->recordedBy?->name ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'pump-counters-'.$date->toDateString().'.pdf',
            title: $labels['title'],
            subtitle: $date->toDateString(),
            headers: [$labels['pump'], $labels['tank'], $labels['reading'], $labels['liters_sold'], $labels['governmental'], $labels['return'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pump_id' => 'required|exists:fuel_pumps,id',
            'tank_id' => 'required|exists:tanks,id',
            'date' => 'required|date',
            'reading_value' => 'required|integer|min:0',
            'governmental_liters' => 'nullable|numeric|min:0',
            'return_liters' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $pump = FuelPump::findOrFail($data['pump_id']);
        $tank = Tank::with('fuelType')->findOrFail($data['tank_id']);

        if ($pump->fuelTypes()->exists() && ! $pump->fuelTypes()->whereKey($tank->fuel_type_id)->exists()) {
            throw ValidationException::withMessages([
                'tank_id' => __('This tank\'s fuel type does not match the pump\'s fuel type.'),
            ]);
        }

        $prevReading = PumpCounterReading::where('pump_id', $pump->id)
            ->latest('id')
            ->first();

        [$litersSold, $transactionId, $governmentalTransactionId, $governmentalLiters, $returnLiters] = $this->computeAndCreateTransaction(
            $pump, $tank, $prevReading, $data['reading_value'], $data['date'], $data['notes'] ?? null,
            $request->user()->id, (float) ($data['governmental_liters'] ?? 0), (float) ($data['return_liters'] ?? 0)
        );

        PumpCounterReading::create([
            'pump_id' => $pump->id,
            'tank_id' => $tank->id,
            'date' => $data['date'],
            'reading_value' => $data['reading_value'],
            'liters_sold' => $litersSold,
            'governmental_liters' => $governmentalLiters,
            'return_liters' => $returnLiters,
            'transaction_id' => $transactionId,
            'governmental_transaction_id' => $governmentalTransactionId,
            'recorded_by_id' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        $message = $litersSold !== null
            ? __(':liters L recorded as a fuel sale.', ['liters' => $litersSold])
            : __('Counter reading saved (no previous reading to compare).');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('pump-counters.index', ['date' => $data['date']]);
    }

    public function edit(PumpCounterReading $pumpCounterReading): Response
    {
        $pumpCounterReading->loadMissing(['pump', 'tank.fuelType']);

        return Inertia::render('pump-counters/edit', [
            'reading' => [
                'id' => $pumpCounterReading->id,
                'pump_id' => $pumpCounterReading->pump_id,
                'tank_id' => $pumpCounterReading->tank_id,
                'date' => $pumpCounterReading->date->toDateString(),
                'reading_value' => (string) $pumpCounterReading->reading_value,
                'liters_sold' => $pumpCounterReading->liters_sold !== null ? (string) $pumpCounterReading->liters_sold : null,
                'governmental_liters' => $pumpCounterReading->governmental_liters !== null ? (string) $pumpCounterReading->governmental_liters : null,
                'return_liters' => $pumpCounterReading->return_liters !== null ? (string) $pumpCounterReading->return_liters : null,
                'notes' => $pumpCounterReading->notes,
            ],
            'pumps' => FuelPump::with('fuelTypes')->orderBy('name')->get()
                ->map(fn (FuelPump $pump) => [
                    'id' => $pump->id,
                    'name' => $pump->name,
                    'fuel_type_ids' => $pump->fuelTypes->pluck('id'),
                ]),
            'tanks' => $this->tankOptions(),
        ]);
    }

    public function update(Request $request, PumpCounterReading $pumpCounterReading): RedirectResponse
    {
        $data = $request->validate([
            'pump_id' => 'required|exists:fuel_pumps,id',
            'tank_id' => 'required|exists:tanks,id',
            'date' => 'required|date',
            'reading_value' => 'required|integer|min:0',
            'governmental_liters' => 'nullable|numeric|min:0',
            'return_liters' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $pump = FuelPump::findOrFail($data['pump_id']);
        $tank = Tank::with('fuelType')->findOrFail($data['tank_id']);

        if ($pump->fuelTypes()->exists() && ! $pump->fuelTypes()->whereKey($tank->fuel_type_id)->exists()) {
            throw ValidationException::withMessages([
                'tank_id' => __('This tank\'s fuel type does not match the pump\'s fuel type.'),
            ]);
        }

        if ($pumpCounterReading->transaction_id) {
            Transaction::find($pumpCounterReading->transaction_id)?->delete();
        }

        if ($pumpCounterReading->governmental_transaction_id) {
            Transaction::find($pumpCounterReading->governmental_transaction_id)?->delete();
        }

        $prevReading = PumpCounterReading::where('pump_id', $pump->id)
            ->where('id', '<', $pumpCounterReading->id)
            ->latest('id')
            ->first();

        [$litersSold, $transactionId, $governmentalTransactionId, $governmentalLiters, $returnLiters] = $this->computeAndCreateTransaction(
            $pump, $tank, $prevReading, $data['reading_value'], $data['date'], $data['notes'] ?? null,
            $request->user()->id, (float) ($data['governmental_liters'] ?? 0), (float) ($data['return_liters'] ?? 0)
        );

        $pumpCounterReading->update([
            'pump_id' => $pump->id,
            'tank_id' => $tank->id,
            'date' => $data['date'],
            'reading_value' => $data['reading_value'],
            'liters_sold' => $litersSold,
            'governmental_liters' => $governmentalLiters,
            'return_liters' => $returnLiters,
            'transaction_id' => $transactionId,
            'governmental_transaction_id' => $governmentalTransactionId,
            'notes' => $data['notes'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reading updated.')]);

        return to_route('pump-counters.index', ['date' => $data['date']]);
    }

    public function destroy(PumpCounterReading $pumpCounterReading): RedirectResponse
    {
        $date = $pumpCounterReading->date->toDateString();

        // Cascades to delete any debt tied to the governmental-sale transaction too
        // (debts.transaction_id is cascadeOnDelete).
        if ($pumpCounterReading->transaction_id) {
            Transaction::find($pumpCounterReading->transaction_id)?->delete();
        }

        if ($pumpCounterReading->governmental_transaction_id) {
            Transaction::find($pumpCounterReading->governmental_transaction_id)?->delete();
        }

        $pumpCounterReading->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reading deleted.')]);

        return to_route('pump-counters.index', ['date' => $date]);
    }

    /**
     * @return array{0: ?float, 1: ?int, 2: ?int, 3: ?float, 4: ?float}
     */
    private function computeAndCreateTransaction(
        FuelPump $pump,
        Tank $tank,
        ?PumpCounterReading $prevReading,
        string $readingValue,
        string $date,
        ?string $notes,
        int $userId,
        float $governmentalLiters = 0,
        float $returnLiters = 0,
    ): array {
        if ($prevReading === null) {
            return [null, null, null, null, null];
        }

        $diff = (float) $readingValue - (float) $prevReading->reading_value;

        if ($diff <= 0) {
            return [null, null, null, null, null];
        }

        $litersSold = round($diff, 3);
        $governmentalLiters = round($governmentalLiters, 3);
        $returnLiters = round($returnLiters, 3);

        if ($governmentalLiters + $returnLiters > $litersSold) {
            throw ValidationException::withMessages([
                'return_liters' => __('Governmental and return liters cannot exceed the liters sold (:liters L).', ['liters' => $litersSold]),
            ]);
        }

        $normalLiters = round($litersSold - $governmentalLiters - $returnLiters, 3);

        $fuelType = $tank->fuelType;
        $currentPrice = $fuelType->currentPrice();
        $currency = $currentPrice ? $currentPrice->currency : Currency::SYP;
        $pricePerLiter = $currentPrice ? (float) $currentPrice->price_per_liter : 0.0;
        $occurredAt = Carbon::parse($date)->midDay();
        $exchangeRate = ExchangeRate::currentRateFor($currency);

        return DB::transaction(function () use (
            $pump, $tank, $normalLiters, $governmentalLiters, $returnLiters, $litersSold,
            $pricePerLiter, $currency, $exchangeRate, $occurredAt, $notes, $userId,
        ) {
            $transactionId = null;
            $governmentalTransactionId = null;

            if ($normalLiters > 0) {
                $transaction = Transaction::create([
                    'user_id' => $userId,
                    'type' => TransactionType::FuelSale,
                    'tank_id' => $tank->id,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'liters' => $normalLiters,
                    'price_per_liter' => $pricePerLiter,
                    'amount' => round($normalLiters * $pricePerLiter, 2),
                    'currency' => $currency,
                    'exchange_rate_to_usd' => $exchangeRate,
                    'occurred_at' => $occurredAt,
                    'description' => $pump->name,
                    'notes' => $notes,
                ]);
                $transactionId = $transaction->id;
            }

            if ($governmentalLiters > 0) {
                $governmentalTransaction = Transaction::create([
                    'user_id' => $userId,
                    'type' => TransactionType::FuelSale,
                    'tank_id' => $tank->id,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'liters' => $governmentalLiters,
                    'price_per_liter' => $pricePerLiter,
                    'amount' => round($governmentalLiters * $pricePerLiter, 2),
                    'currency' => $currency,
                    'exchange_rate_to_usd' => $exchangeRate,
                    'occurred_at' => $occurredAt,
                    'description' => $pump->name.' — '.__('Governmental sale'),
                    'notes' => $notes,
                    'is_governmental' => true,
                ]);

                $governmentalTransaction->debt()->create([
                    'debtor_id' => Debtor::government()->id,
                    'amount' => $governmentalTransaction->amount,
                    'currency' => $governmentalTransaction->currency,
                    'exchange_rate_to_usd' => $governmentalTransaction->exchange_rate_to_usd,
                    'date' => $occurredAt->toDateString(),
                    'status' => DebtStatus::Outstanding,
                    'recorded_by_id' => $userId,
                ]);

                $governmentalTransactionId = $governmentalTransaction->id;
            }

            return [
                $litersSold,
                $transactionId,
                $governmentalTransactionId,
                $governmentalLiters > 0 ? $governmentalLiters : null,
                $returnLiters > 0 ? $returnLiters : null,
            ];
        });
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
            ]);
    }
}
