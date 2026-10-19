<?php

namespace App\Http\Controllers;

use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debtor;
use App\Models\FuelPump;
use App\Models\FuelType;
use App\Models\PumpCounterReading;
use App\Models\Shift;
use App\Models\Tank;
use App\Models\Transaction;
use App\Services\FuelCostAllocationService;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use App\Support\Currency;
use App\Support\Locales;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PumpCounterReadingController extends Controller
{
    public function __construct(private readonly FuelCostAllocationService $allocationService) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->historyRange($request);

        $pumps = FuelPump::with('fuelTypes')->orderBy('name')->get()
            ->map(function (FuelPump $pump) use ($from, $to) {
                $latest = $pump->counterReadings()->chronological('desc')->first();
                $dailyLiters = (float) $pump->counterReadings()
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to)
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
                        // When it was typed in (the date above is the day it counts for).
                        'recorded_at' => $latest->created_at?->toIso8601String(),
                    ] : null,
                ];
            });

        $readings = PumpCounterReading::with(['pump', 'tank.fuelType', 'recordedBy', 'shift'])
            ->whereDate('pump_counter_readings.date', '>=', $from)
            ->whereDate('pump_counter_readings.date', '<=', $to)
            ->chronological('desc')
            ->get()
            ->each(function (PumpCounterReading $reading) {
                $reading->previous_reading_value = $this->previousReadingFor($reading)?->reading_value;
            });

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

        $governmentalTotals = $readings
            ->filter(fn (PumpCounterReading $reading) => $reading->governmental_liters !== null && $reading->tank !== null)
            ->groupBy(fn (PumpCounterReading $reading) => $reading->tank->fuel_type_id)
            ->map(fn ($group) => [
                'fuel_type_id' => $group->first()->tank->fuel_type_id,
                'fuel_type_name' => $group->first()->tank->fuelType?->name,
                'liters_sold' => round((float) $group->sum('governmental_liters'), 3),
            ])
            ->sortBy('fuel_type_name')
            ->values();

        return Inertia::render('pump-counters/index', [
            'pumps' => $pumps,
            'lastEntry' => $this->lastEntry(),
            'entry' => $this->entryContext(
                ($request->date('entry_date') ?? today())->toDateString(),
                Shift::find($request->integer('entry_shift')) ?? Shift::defaultShift(),
                $request->has('entry_date'),
            ),
            'shifts' => $this->shiftOptions(),
            'tanks' => $this->tankOptions(),
            'readings' => $readings,
            'fuelTypeTotals' => $fuelTypeTotals,
            'governmentalTotals' => $governmentalTotals,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        [$from, $to] = $this->historyRange($request);
        $direction = Locales::direction();

        $readings = PumpCounterReading::with(['pump', 'tank.fuelType', 'recordedBy'])
            ->whereDate('pump_counter_readings.date', '>=', $from)
            ->whereDate('pump_counter_readings.date', '<=', $to)
            ->chronological('desc')
            ->get();

        $labels = Locales::labels([
            'title' => 'Pump Counter Readings',
            'pump' => 'Pump',
            'tank' => 'Tank',
            'reading' => 'Counter value',
            'liters_sold' => 'Liters sold',
            'governmental' => 'Governmental sale (L)',
            'return' => 'Return (L)',
            'recorded_by' => 'Recorded by',
        ]);

        $rows = $readings->map(fn (PumpCounterReading $reading) => [
            $reading->pump->name ?? '—',
            $reading->tank->name ?? '—',
            number_format((float) $reading->reading_value, 0),
            $reading->liters_sold !== null ? number_format((float) $reading->liters_sold, 3).' L' : '—',
            $reading->governmental_liters !== null ? number_format((float) $reading->governmental_liters, 3).' L' : '—',
            $reading->return_liters !== null ? number_format((float) $reading->return_liters, 3).' L' : '—',
            $reading->recordedBy->name ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'pump-counters-'.$from->toDateString().($from->equalTo($to) ? '' : '-to-'.$to->toDateString()).'.pdf',
            title: $labels['title'],
            subtitle: $from->equalTo($to) ? $from->toDateString() : $from->toDateString().' — '.$to->toDateString(),
            headers: [$labels['pump'], $labels['tank'], $labels['reading'], $labels['liters_sold'], $labels['governmental'], $labels['return'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    /**
     * One monthly ledger per fuel type — mirrors the old manual spreadsheet's per-fuel-type
     * daily tabs (one row per calendar day, days with no activity still appear rather than
     * disappearing). Parameterized by fuel type rather than hardcoded to two, since fuel types
     * aren't hardcoded anywhere else in this app.
     *
     * The first row is a seed dated the day before the range starts, showing each pump's last
     * known reading (blank if there isn't one) so it can be confirmed or corrected by hand rather
     * than trusting a number computed silently from whatever history happens to exist. Every real
     * day's Total/Sold/Net Liters then reads as a plain formula against the row directly above —
     * no special-cased first row — and a pump not updated on a given day carries its last known
     * reading forward instead of leaving that cell blank.
     */
    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        $fuelType = FuelType::findOrFail($request->integer('fuel_type_id'));
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $tankIds = Tank::where('fuel_type_id', $fuelType->id)->pluck('id');

        // whereDate(), not where('date', ...), since this column stores a full "Y-m-d H:i:s"
        // string -- comparing that against a bare "Y-m-d" boundary with <= is false for a
        // reading dated exactly on the boundary (the longer string sorts after the short one),
        // which was silently dropping any reading dated on the exact last day of the range.
        $readingsByDay = PumpCounterReading::with('pump')
            ->whereIn('tank_id', $tankIds)
            ->whereDate('pump_counter_readings.date', '>=', $from->toDateString())
            ->whereDate('pump_counter_readings.date', '<=', $to->toDateString())
            ->chronological()
            ->get()
            ->groupBy(fn (PumpCounterReading $reading) => $reading->date->toDateString());

        // One column per pump that has ever read a tank of this fuel type up to the end of the
        // range -- not just pumps active within it, so an idle pump still gets a (carried
        // forward) column instead of silently disappearing for the period it wasn't touched.
        $pumps = PumpCounterReading::with('pump')
            ->whereIn('tank_id', $tankIds)
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->pluck('pump')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        // The last reading recorded for each pump strictly before the range starts -- carried
        // forward as that pump's value on any day within the range it isn't actually updated
        // ("since I didn't update the reading, put it as it is"). Shown as an explicit, editable
        // seed row (below) rather than a number baked invisibly into a formula, since it isn't
        // always reliable: a pump with no prior reading at all has nothing to show here, and the
        // station knows the real starting value better than any guess this could make.
        $lastKnownReading = PumpCounterReading::whereIn('tank_id', $tankIds)
            ->whereDate('pump_counter_readings.date', '<', $from->toDateString())
            ->chronological()
            ->get()
            ->groupBy('pump_id')
            ->map(fn ($readings) => (int) $readings->last()->reading_value)
            ->all();

        $labels = Locales::labels([
            'date' => 'Date',
            'total' => 'Grand total',
            'liters_sold' => 'Liters Sold (gross)',
            'governmental' => 'Governmental (L)',
            'return' => 'Return (L)',
            'net_liters' => 'Net Liters',
            'price_per_liter' => 'Price/Liter (SYP)',
            'amount' => 'Amount (SYP)',
        ]);

        $pumpCount = $pumps->count();
        $totalCol = Coordinate::stringFromColumnIndex($pumpCount + 2);
        $soldCol = Coordinate::stringFromColumnIndex($pumpCount + 3);
        $govCol = Coordinate::stringFromColumnIndex($pumpCount + 4);
        $returnCol = Coordinate::stringFromColumnIndex($pumpCount + 5);
        $netLitersCol = Coordinate::stringFromColumnIndex($pumpCount + 6);
        $priceCol = Coordinate::stringFromColumnIndex($pumpCount + 7);
        $firstPumpCol = Coordinate::stringFromColumnIndex(2);
        $lastPumpCol = Coordinate::stringFromColumnIndex($pumpCount + 1);

        $rows = [];
        $firstDataRow = 5; // title, subtitle, blank, header, then data

        // Seed row, dated the day before the range: each pump's last known reading if one
        // exists, blank otherwise -- editable/correctable in the sheet, not a hidden number.
        // Every real day below reads as a plain "this row minus the row above" formula, this
        // row included, so there's no special-cased first row.
        $seedRow = [$from->copy()->subDay()->toDateString()];

        foreach ($pumps as $pump) {
            $seedRow[] = $lastKnownReading[$pump->id] ?? null;
        }

        $seedRow[] = "=SUM({$firstPumpCol}{$firstDataRow}:{$lastPumpCol}{$firstDataRow})";
        $seedRow[] = null; // liters sold -- nothing to compare the seed row against
        $seedRow[] = null; // governmental
        $seedRow[] = null; // return
        $seedRow[] = null; // net liters
        $seedRow[] = null; // price/liter
        $seedRow[] = null; // amount
        $rows[] = $seedRow;

        foreach (CarbonPeriod::create($from, $to) as $i => $day) {
            $dayKey = $day->toDateString();
            $dayReadings = $readingsByDay->get($dayKey, collect());
            $thisRow = $firstDataRow + 1 + $i;
            $previousRow = $thisRow - 1;

            // A pump can log more than one reading the same day (several shifts, or a tank
            // switch) -- the closing one (last shift, last saved) is what the column shows.
            $closingReadingByPumpId = $dayReadings->groupBy('pump_id')
                ->map(fn ($readings) => (int) $readings->last()->reading_value);

            foreach ($pumps as $pump) {
                if ($closingReadingByPumpId->has($pump->id)) {
                    $lastKnownReading[$pump->id] = $closingReadingByPumpId->get($pump->id);
                }
            }

            $governmentalLiters = (float) $dayReadings->sum('governmental_liters');
            $returnLiters = (float) $dayReadings->sum('return_liters');

            $priceAtDay = $fuelType->priceAt($day->copy()->midDay());

            $row = [$dayKey];

            foreach ($pumps as $pump) {
                $row[] = $lastKnownReading[$pump->id] ?? null;
            }

            $row[] = "=SUM({$firstPumpCol}{$thisRow}:{$lastPumpCol}{$thisRow})";
            // A baseline reading (the row right after an empty seed/no prior total) isn't a real
            // sales day -- it's establishing where the counter starts -- so its own cumulative
            // value must not be read as "liters sold that day". Guarded on the previous row's
            // Total being 0 rather than on which row this is, so it's correct however many
            // baseline-only rows come before real daily entries start.
            $row[] = "=IF({$totalCol}{$previousRow}=0,0,{$totalCol}{$thisRow}-{$totalCol}{$previousRow})";
            $row[] = $governmentalLiters > 0 ? round($governmentalLiters, 0) : null;
            $row[] = $returnLiters > 0 ? round($returnLiters, 0) : null;
            $row[] = "={$soldCol}{$thisRow}-{$govCol}{$thisRow}-{$returnCol}{$thisRow}";
            $row[] = $priceAtDay ? round((float) $priceAtDay->price_per_liter, 3) : null;
            // Amount = net liters x price/liter -- a live formula, not the real recorded
            // transaction total, so it stays self-consistent with the two cells beside it and
            // recalculates automatically if either is corrected. Left blank (not a misleading 0)
            // whenever there's no price for the day, same as before.
            $row[] = $priceAtDay ? "={$netLitersCol}{$thisRow}*{$priceCol}{$thisRow}" : null;

            $rows[] = $row;
        }

        return $exporter->download(
            filename: 'pump-counters-'.$fuelType->slug.'-'.$from->toDateString().'-to-'.$to->toDateString().'.xlsx',
            title: $fuelType->name,
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: [
                $labels['date'],
                ...$pumps->pluck('name'),
                $labels['total'],
                $labels['liters_sold'],
                $labels['governmental'],
                $labels['return'],
                $labels['net_liters'],
                $labels['price_per_liter'],
                $labels['amount'],
            ],
            rows: $rows,
            direction: 'ltr',
            // array union (+), not spread (...), since spread silently renumbers integer keys.
            columnFormats: array_fill_keys(range(1, $pumpCount + 5), '#,##0') + [
                $pumpCount + 6 => '#,##0.000',
                $pumpCount + 7 => '#,##0.00',
            ],
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pump_id' => 'required|exists:fuel_pumps,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'tank_id' => 'required|exists:tanks,id',
            'date' => 'required|date',
            'reading_value' => 'required|integer|min:0',
            'governmental_liters' => 'nullable|numeric|min:0',
            'return_liters' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $reading = $this->createReading($data, $request->user()->id);

        $message = $reading->liters_sold !== null
            ? __(':liters L recorded as a fuel sale.', ['liters' => $reading->liters_sold])
            : __('Counter reading saved (no previous reading to compare).');

        $this->flashWithStockCheck($message, [(int) $data['tank_id']]);

        return to_route('pump-counters.index', ['from' => $data['date'], 'to' => $data['date'], 'entry_date' => $data['date'], 'entry_shift' => $reading->shift_id]);
    }

    /**
     * Saves counter values for several pumps in one request. Reuses createReading() for each
     * row so every reading is created exactly as it would be through the single-reading store()
     * -- its own Transaction, its own governmental Debt where applicable, and the same
     * recompute-the-next-reading step -- just triggered once per pump instead of once per
     * request. The whole batch is wrapped in one transaction: if any row fails validation
     * (fuel-type/tank mismatch, or governmental+return exceeding liters sold), nothing in the
     * batch is saved, and the failing row's own errors are reported back keyed by its index so
     * the frontend can point at the exact row.
     *
     * The entry table only ever adds readings: saved ones are changed on the edit page. A pump
     * that already has a reading in that date + shift gets another one (a tank switch); the
     * entry table asks first.
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'shift_id' => 'nullable|exists:shifts,id',
            'readings' => 'required|array|min:1',
            'readings.*.pump_id' => 'required|exists:fuel_pumps,id',
            'readings.*.tank_id' => 'nullable|required_with:readings.*.reading_value|exists:tanks,id',
            'readings.*.reading_value' => 'nullable|integer|min:0',
            'readings.*.governmental_liters' => 'nullable|numeric|min:0',
            'readings.*.return_liters' => 'nullable|numeric|min:0',
            'readings.*.notes' => 'nullable|string|max:500',
        ]);

        $rowsToSave = collect(Arr::array($validated, 'readings'))
            ->map(fn ($row, $index) => $row + ['_index' => $index])
            ->filter(fn ($row) => filled($row['reading_value'] ?? null))
            ->values();

        if ($rowsToSave->isEmpty()) {
            throw ValidationException::withMessages([
                'readings' => __('Enter at least one counter value.'),
            ]);
        }

        $shiftId = (int) ($validated['shift_id'] ?? Shift::defaultShift()->id);

        $savedCount = DB::transaction(function () use ($rowsToSave, $validated, $request, $shiftId) {
            $errors = [];
            $count = 0;

            foreach ($rowsToSave as $row) {
                try {
                    $this->createReading([...$row, 'date' => $validated['date'], 'shift_id' => $shiftId], $request->user()->id);
                    $count++;
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors["readings.{$row['_index']}.{$field}"] = $messages;
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $count;
        });

        $message = $savedCount === 1
            ? __('1 reading saved.')
            : __(':count readings saved.', ['count' => $savedCount]);

        $this->flashWithStockCheck($message, $rowsToSave->pluck('tank_id')->map(fn ($id) => (int) $id)->all());

        return to_route('pump-counters.index', ['from' => $validated['date'], 'to' => $validated['date'], 'entry_date' => $validated['date'], 'entry_shift' => $shiftId]);
    }

    /**
     * Creates one pump counter reading -- the fuel-type/tank match check, the transaction that
     * finds the previous reading, computes liters sold and creates the Transaction/Debt, and the
     * recompute-the-next-reading step. Shared by store() and storeBulk() so both go through
     * identical business logic.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function createReading(array $data, int $userId): PumpCounterReading
    {
        $pump = FuelPump::findOrFail((int) $data['pump_id']);
        $tank = Tank::with('fuelType')->findOrFail((int) $data['tank_id']);

        if ($pump->fuelTypes()->exists() && ! $pump->fuelTypes()->whereKey($tank->fuel_type_id)->exists()) {
            throw ValidationException::withMessages([
                'tank_id' => __('This tank\'s fuel type does not match the pump\'s fuel type.'),
            ]);
        }

        $shift = filled($data['shift_id'] ?? null) ? Shift::findOrFail((int) $data['shift_id']) : Shift::defaultShift();

        return DB::transaction(function () use ($data, $pump, $tank, $userId, $shift) {
            // A new reading goes after any the pump already has in its date + shift.
            [$prevReading, $nextReading] = $this->neighbours($pump->id, $data['date'], $shift);
            $this->assertBetweenNeighbours((int) $data['reading_value'], $prevReading, $nextReading);

            // A backdated entry lands before a reading that already exists, whose sale was
            // computed against its old predecessor. That sale is released first, so this earlier
            // day draws its fuel cost before the later one does.
            if ($nextReading !== null) {
                $this->releaseSales($nextReading);
            }

            [$litersSold, $transactionId, $governmentalTransactionId, $governmentalLiters, $returnLiters] = $this->computeAndCreateTransaction(
                $pump, $tank, $prevReading, $data['reading_value'], $data['date'], $data['notes'] ?? null,
                $userId, (float) ($data['governmental_liters'] ?? 0), (float) ($data['return_liters'] ?? 0)
            );

            $reading = PumpCounterReading::create([
                'pump_id' => $pump->id,
                'shift_id' => $shift->id,
                'tank_id' => $tank->id,
                'date' => $data['date'],
                'reading_value' => $data['reading_value'],
                'liters_sold' => $litersSold,
                'governmental_liters' => $governmentalLiters,
                'return_liters' => $returnLiters,
                'transaction_id' => $transactionId,
                'governmental_transaction_id' => $governmentalTransactionId,
                'recorded_by_id' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($nextReading !== null) {
                $this->recomputeReading($nextReading, $reading);
            }

            return $reading;
        });
    }

    public function edit(PumpCounterReading $pumpCounterReading): Response
    {
        $pumpCounterReading->loadMissing(['pump', 'tank.fuelType']);

        return Inertia::render('pump-counters/edit', [
            'reading' => [
                'id' => $pumpCounterReading->id,
                'pump_id' => $pumpCounterReading->pump_id,
                'shift_id' => $pumpCounterReading->shift_id,
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
            'shifts' => $this->shiftOptions(),
        ]);
    }

    public function update(Request $request, PumpCounterReading $pumpCounterReading): RedirectResponse
    {
        $data = $request->validate([
            'pump_id' => 'required|exists:fuel_pumps,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'tank_id' => 'required|exists:tanks,id',
            'date' => 'required|date',
            'reading_value' => 'required|integer|min:0',
            'governmental_liters' => 'nullable|numeric|min:0',
            'return_liters' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->updateReading($pumpCounterReading, $data, $request->user()->id);

        $this->flashWithStockCheck(__('Reading updated.'), [(int) $data['tank_id']]);

        return to_route('pump-counters.index', ['from' => $data['date'], 'to' => $data['date'], 'entry_date' => $data['date'], 'entry_shift' => $pumpCounterReading->shift_id]);
    }

    /**
     * Updates a saved reading and everything derived from it: its own sale, the reading after
     * it, and (when it moved to another day or pump) the reading that used to follow it. Shared
     * by the edit page and the entry table, so both recalculate the same way.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateReading(PumpCounterReading $pumpCounterReading, array $data, int $userId): void
    {
        $pump = FuelPump::findOrFail((int) $data['pump_id']);
        $tank = Tank::with('fuelType')->findOrFail((int) $data['tank_id']);

        if ($pump->fuelTypes()->exists() && ! $pump->fuelTypes()->whereKey($tank->fuel_type_id)->exists()) {
            throw ValidationException::withMessages([
                'tank_id' => __('This tank\'s fuel type does not match the pump\'s fuel type.'),
            ]);
        }

        // A reading keeps its shift unless the edit moves it to another one.
        $shift = filled($data['shift_id'] ?? null) ? Shift::findOrFail((int) $data['shift_id']) : ($pumpCounterReading->shift ?? Shift::defaultShift());

        DB::transaction(function () use ($userId, $data, $pump, $tank, $pumpCounterReading, $shift) {
            // The reading after its old place, and the readings around its new place (itself
            // left out: its row still holds the old date, shift and value).
            $oldNextReading = $this->nextReadingFor($pumpCounterReading);
            [$prevReading, $nextReading] = $this->neighbours($pump->id, $data['date'], $shift, $pumpCounterReading->id);
            $this->assertBetweenNeighbours((int) $data['reading_value'], $prevReading, $nextReading);

            // Every sale this edit changes is released before any is recreated, so each one
            // draws its fuel cost back in order.
            foreach (array_filter([$pumpCounterReading, $oldNextReading, $nextReading]) as $affected) {
                $this->releaseSales($affected);
            }

            [$litersSold, $transactionId, $governmentalTransactionId, $governmentalLiters, $returnLiters] = $this->computeAndCreateTransaction(
                $pump, $tank, $prevReading, (string) $data['reading_value'], $data['date'], $data['notes'] ?? null,
                $userId, (float) ($data['governmental_liters'] ?? 0), (float) ($data['return_liters'] ?? 0)
            );

            $pumpCounterReading->update([
                'pump_id' => $pump->id,
                'shift_id' => $shift->id,
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

            // The next reading's liters sold were computed against this one's old value (or
            // against another reading, if the date or shift moved), so they're recomputed against
            // it. When the reading moved to another day, shift or pump, the reading that used to follow it now
            // follows its old predecessor instead. Nothing further out is affected: a reading
            // two or more steps ahead derives its liters from its immediate predecessor.
            if ($nextReading !== null) {
                $this->recomputeReading($nextReading, $pumpCounterReading);
            }

            if ($oldNextReading !== null && $oldNextReading->id !== $nextReading?->id) {
                $oldNextReading->refresh();
                $this->recomputeReading($oldNextReading, $this->previousReadingFor($oldNextReading));
            }
        });
    }

    public function destroy(PumpCounterReading $pumpCounterReading): RedirectResponse
    {
        $date = $pumpCounterReading->date->toDateString();

        DB::transaction(function () use ($pumpCounterReading) {
            // The next reading's liters were counted from this one; without it they count from
            // the reading before, so its sale is recomputed (released first, like the deleted
            // reading's own, so the fuel cost goes back before it's drawn again).
            $nextReading = $this->nextReadingFor($pumpCounterReading);
            $prevReading = $this->previousReadingFor($pumpCounterReading);

            foreach (array_filter([$pumpCounterReading, $nextReading]) as $affected) {
                $this->releaseSales($affected);
            }

            $pumpCounterReading->delete();

            if ($nextReading !== null) {
                $this->recomputeReading($nextReading, $prevReading);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reading deleted.')]);

        return to_route('pump-counters.index', ['from' => $date, 'to' => $date]);
    }

    /**
     * The history range from the request -- a single day (today, by default) or any from/to
     * span. A range with only one end given collapses to that one day.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function historyRange(Request $request): array
    {
        $from = $request->date('from') ?? $request->date('to') ?? today();
        $to = $request->date('to') ?? $from;

        return $from->greaterThan($to) ? [$to->startOfDay(), $from->startOfDay()] : [$from->startOfDay(), $to->startOfDay()];
    }

    /**
     * The pump's readings just before and just after a place in its history: the end of
     * $shift on $date for a new reading, or the place of reading $id (left out of both).
     * Readings run in order of day, shift position, then save order.
     *
     * @return array{0: ?PumpCounterReading, 1: ?PumpCounterReading}
     */
    private function neighbours(int $pumpId, string $date, Shift $shift, ?int $id = null): array
    {
        $readings = fn () => PumpCounterReading::where('pump_counter_readings.pump_id', $pumpId)
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id));

        return [
            $readings()->before($date, $shift->position, $id)->chronological('desc')->first(),
            $readings()->after($date, $shift->position, $id)->chronological()->first(),
        ];
    }

    /**
     * A reading placed before a later one must sit between the readings around it: the meter
     * only counts up, so anything outside is a typo that would leave a day with negative sales.
     * The newest reading has nothing after it and keeps its old rule (a lower value records no
     * sale, e.g. after a meter was replaced).
     */
    private function assertBetweenNeighbours(int $value, ?PumpCounterReading $previous, ?PumpCounterReading $next): void
    {
        if ($next === null) {
            return;
        }

        $tooLow = $previous !== null && $value < (int) $previous->reading_value;

        if (! $tooLow && $value <= (int) $next->reading_value) {
            return;
        }

        throw ValidationException::withMessages([
            'reading_value' => $previous !== null
                ? __('The counter value must be between :previous (reading of :previous_date) and :next (reading of :next_date).', [
                    'previous' => number_format((float) $previous->reading_value, 0),
                    'previous_date' => $previous->date->format('d/m/Y'),
                    'next' => number_format((float) $next->reading_value, 0),
                    'next_date' => $next->date->format('d/m/Y'),
                ])
                : __('The counter value cannot be higher than the next reading, :next (reading of :next_date).', [
                    'next' => number_format((float) $next->reading_value, 0),
                    'next_date' => $next->date->format('d/m/Y'),
                ]),
        ]);
    }

    /**
     * Deletes a reading's sales (and its governmental debt, by cascade). Deleting a sale also
     * deletes its fuel cost allocations, which hands the liters back to the cost layers.
     */
    private function releaseSales(PumpCounterReading $reading): void
    {
        foreach (array_filter([$reading->transaction_id, $reading->governmental_transaction_id]) as $transactionId) {
            Transaction::find($transactionId)?->delete();
        }
    }

    /** The reading immediately before the given one for the same pump; see nextReadingFor(). */
    private function previousReadingFor(PumpCounterReading $reading): ?PumpCounterReading
    {
        return $this->neighbours($reading->pump_id, $reading->date->toDateString(), $reading->shift ?? Shift::defaultShift(), $reading->id)[0];
    }

    /**
     * The reading immediately after the given one, for the same pump (day, then shift, then
     * save order: a shift can hold more than one reading when the pump switched tank) -- the
     * only reading whose own liters sold is derived directly from this one's reading_value.
     */
    private function nextReadingFor(PumpCounterReading $reading): ?PumpCounterReading
    {
        return $this->neighbours($reading->pump_id, $reading->date->toDateString(), $reading->shift ?? Shift::defaultShift(), $reading->id)[1];
    }

    /**
     * Recomputes $reading's liters sold against $prevReading and regenerates its
     * transaction/debt to match, replacing whatever was there before — used to keep a
     * reading's derived figures in sync after the reading that precedes it changes.
     */
    private function recomputeReading(PumpCounterReading $reading, ?PumpCounterReading $prevReading): void
    {
        $this->releaseSales($reading);

        $pump = FuelPump::findOrFail($reading->pump_id);
        $tank = Tank::with('fuelType')->findOrFail($reading->tank_id);

        try {
            [$litersSold, $transactionId, $governmentalTransactionId, $governmentalLiters, $returnLiters] = $this->computeAndCreateTransaction(
                $pump, $tank, $prevReading, (string) $reading->reading_value, $reading->date->toDateString(), $reading->notes,
                $reading->recorded_by_id, (float) ($reading->governmental_liters ?? 0), (float) ($reading->return_liters ?? 0)
            );
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'reading_value' => __('This change leaves too little liters sold on the next reading (:date) for its recorded governmental/return liters — adjust that reading first.', ['date' => $reading->date->toDateString()]),
            ]);
        }

        $reading->update([
            'liters_sold' => $litersSold,
            'governmental_liters' => $governmentalLiters,
            'return_liters' => $returnLiters,
            'transaction_id' => $transactionId,
            'governmental_transaction_id' => $governmentalTransactionId,
        ]);
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
        $occurredAt = Carbon::parse($date)->midDay();
        // The price that actually applied on the reading's own date, not whatever's current
        // right now — matters once a reading is backdated to before a later price change.
        $priceAtDate = $fuelType->priceAt($occurredAt);
        $currency = $priceAtDate ? $priceAtDate->currency : Currency::SYP;
        $pricePerLiter = $priceAtDate ? (float) $priceAtDate->price_per_liter : 0.0;

        return DB::transaction(function () use (
            $pump, $tank, $normalLiters, $governmentalLiters, $returnLiters, $litersSold,
            $pricePerLiter, $currency, $occurredAt, $notes, $userId,
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
                    'exchange_rate_to_usd' => null,
                    'occurred_at' => $occurredAt,
                    'description' => $pump->name,
                    'notes' => $notes,
                ]);
                $this->allocationService->allocate($transaction);
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
                    'exchange_rate_to_usd' => null,
                    'occurred_at' => $occurredAt,
                    'description' => $pump->name.' — '.__('Governmental sale'),
                    'notes' => $notes,
                    'is_governmental' => true,
                ]);

                $this->allocationService->allocate($governmentalTransaction);

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

    /**
     * The most recent counter reading across all pumps, for the "latest entry in the system"
     * hint above the entry table: the day it counts for and when it was typed in.
     *
     * @return array{date: string, recorded_at: string|null}|null
     */
    private function lastEntry(): ?array
    {
        $latest = PumpCounterReading::query()->chronological('desc')->first();

        return $latest ? [
            'date' => $latest->date->toDateString(),
            'recorded_at' => $latest->created_at?->toIso8601String(),
        ] : null;
    }

    /**
     * What the entry table needs for the date + shift it's entering, per pump: the readings a
     * new value must sit between (the last one up to the end of that shift, the first one after
     * it) and the readings already saved in that date + shift. Saved readings are never put back
     * into the inputs: the table shows a check and an edit link for them, and a value typed for
     * such a pump is an additional reading.
     *
     * @return array{date: string, shift_id: int, requested: bool, pumps: array<int, array<string, mixed>>}
     */
    private function entryContext(string $date, Shift $shift, bool $requested): array
    {
        return [
            'date' => $date,
            'shift_id' => $shift->id,
            'requested' => $requested,
            'pumps' => FuelPump::orderBy('name')->get()->map(function (FuelPump $pump) use ($date, $shift) {
                [$previous, $next] = $this->neighbours($pump->id, $date, $shift);

                return [
                    'pump_id' => $pump->id,
                    'previous' => $previous ? [
                        'reading_value' => $previous->reading_value,
                        'date' => $previous->date->toDateString(),
                        'recorded_at' => $previous->created_at?->toIso8601String(),
                    ] : null,
                    'next' => $next ? [
                        'reading_value' => $next->reading_value,
                        'date' => $next->date->toDateString(),
                    ] : null,
                    'recorded' => PumpCounterReading::where('pump_id', $pump->id)
                        ->whereDate('date', $date)
                        ->where('shift_id', $shift->id)
                        ->orderBy('id')
                        ->get(['id', 'reading_value'])
                        ->map(fn (PumpCounterReading $reading) => ['id' => $reading->id, 'reading_value' => $reading->reading_value])
                        ->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The station's shifts for the entry table and edit page, in order. Inactive ones are
     * included (flagged) so an older reading's shift still shows; the selector offers only
     * active ones, and none at all on a single-shift station.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shiftOptions(): array
    {
        return Shift::orderBy('position')->get()->map(fn (Shift $shift) => $shift->toOption())->values()->all();
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
            ])
            ->all();
    }

    /**
     * The save toast, turned into a warning when a tank the readings drew from now holds less
     * than nothing: sales were recorded that the tank's stock can't cover, which means a missing
     * delivery or a mistyped counter. The readings are kept either way; the station may well
     * have sold that fuel and recorded the delivery late.
     *
     * @param  array<int>  $tankIds
     */
    private function flashWithStockCheck(string $message, array $tankIds): void
    {
        $belowZero = Tank::whereIn('id', array_unique($tankIds))->get()
            ->filter(fn (Tank $tank) => $tank->expectedLiters() < -0.0005)
            ->map(fn (Tank $tank) => __(':tank is below zero (:liters L).', [
                'tank' => $tank->name,
                'liters' => number_format($tank->expectedLiters(), 0),
            ]));

        if ($belowZero->isEmpty()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

            return;
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => $message.' '.$belowZero->implode(' ').' '
            .__('Record the missing delivery, or check the counter reading.')]);
    }
}
