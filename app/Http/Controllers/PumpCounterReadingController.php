<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\FuelPump;
use App\Models\FuelType;
use App\Models\PumpCounterReading;
use App\Models\Tank;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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
            ->get()
            ->each(function (PumpCounterReading $reading) {
                $reading->previous_reading_value = PumpCounterReading::where('pump_id', $reading->pump_id)
                    ->where(fn ($q) => $q->where('date', '<', $reading->date)
                        ->orWhere(fn ($q2) => $q2->where('date', $reading->date)->where('id', '<', $reading->id)))
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->value('reading_value');
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
            'tanks' => $this->tankOptions(),
            'readings' => $readings,
            'fuelTypeTotals' => $fuelTypeTotals,
            'governmentalTotals' => $governmentalTotals,
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
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->orderBy('id')
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
            ->whereDate('date', '<', $from->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy('pump_id')
            ->map(fn ($readings) => (int) $readings->last()->reading_value)
            ->all();

        $labels = app()->getLocale() === 'ar' ? [
            'date' => 'التاريخ',
            'total' => 'المجموع الكامل',
            'liters_sold' => 'المباع (لتر)',
            'governmental' => 'حكومي (لتر)',
            'return' => 'مرتجع (لتر)',
            'net_liters' => 'الصافي (لتر)',
            'price_per_liter' => 'سعر الليتر (ل.س)',
            'amount' => 'المبلغ (ل.س)',
        ] : [
            'date' => 'Date',
            'total' => 'Total',
            'liters_sold' => 'Liters Sold (gross)',
            'governmental' => 'Governmental (L)',
            'return' => 'Return (L)',
            'net_liters' => 'Net Liters',
            'price_per_liter' => 'Price/Liter (SYP)',
            'amount' => 'Amount (SYP)',
        ];

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

            // A pump can log more than one reading the same day (e.g. shared across tanks) --
            // the closing (last recorded) reading_value is what the column shows for that day.
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

        $litersSold = DB::transaction(function () use ($request, $data, $pump, $tank) {
            // whereDate(), not where('date', ...): the column stores a full "Y-m-d H:i:s"
            // string, so a bare <= comparison against $data['date'] (just "Y-m-d") is false for
            // a reading dated on that same day (the longer string sorts after the short one) --
            // silently skipping same-day predecessors and picking an earlier day's reading
            // instead, which computes the wrong liters sold.
            $prevReading = PumpCounterReading::where('pump_id', $pump->id)
                ->whereDate('date', '<=', $data['date'])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();

            [$litersSold, $transactionId, $governmentalTransactionId, $governmentalLiters, $returnLiters] = $this->computeAndCreateTransaction(
                $pump, $tank, $prevReading, $data['reading_value'], $data['date'], $data['notes'] ?? null,
                $request->user()->id, (float) ($data['governmental_liters'] ?? 0), (float) ($data['return_liters'] ?? 0)
            );

            $reading = PumpCounterReading::create([
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

            // A backdated entry can land before a reading that already exists — that later
            // reading's own liters sold was computed against whatever used to be its immediate
            // predecessor, which is now stale (its true predecessor is this new reading instead).
            $nextReading = $this->nextReadingFor($reading);

            if ($nextReading !== null) {
                $this->recomputeReading($nextReading, $reading);
            }

            return $litersSold;
        });

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

        DB::transaction(function () use ($request, $data, $pump, $tank, $pumpCounterReading) {
            if ($pumpCounterReading->transaction_id) {
                Transaction::find($pumpCounterReading->transaction_id)?->delete();
            }

            if ($pumpCounterReading->governmental_transaction_id) {
                Transaction::find($pumpCounterReading->governmental_transaction_id)?->delete();
            }

            // This query runs before the row below is saved, so the reading's own still-old DB
            // state could otherwise match itself here if the date is changing (e.g. moving it
            // later would make its own pre-update date satisfy "< new date") — excluded by id
            // explicitly rather than relying on the date/id comparison alone. whereDate(), not
            // where('date', ...), for the same reason as store(): the column stores a full
            // datetime string, so a bare equality/less-than check against $data['date'] ("Y-m-d"
            // only) never matches a same-day row at all.
            $prevReading = PumpCounterReading::where('pump_id', $pump->id)
                ->where('id', '!=', $pumpCounterReading->id)
                ->where(fn ($q) => $q->whereDate('date', '<', $data['date'])
                    ->orWhere(fn ($q2) => $q2->whereDate('date', $data['date'])->where('id', '<', $pumpCounterReading->id)))
                ->orderByDesc('date')
                ->orderByDesc('id')
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

            // This reading's value just changed — the very next reading for this pump (if any)
            // had its own liters sold computed against the *old* value, so it's now stale and
            // needs recomputing against the new one. Nothing further out is affected: a reading
            // two or more steps ahead derives its liters sold from its immediate predecessor's
            // reading_value, which hasn't changed.
            $nextReading = $this->nextReadingFor($pumpCounterReading);

            if ($nextReading !== null) {
                $this->recomputeReading($nextReading, $pumpCounterReading);
            }
        });

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
     * The reading immediately after the given one chronologically, for the same pump (id only
     * breaks ties between same-day readings — a pump's meter can log more than one reading a
     * day when it's shared across tanks) — the only reading whose own liters sold is derived
     * directly from this one's reading_value.
     */
    private function nextReadingFor(PumpCounterReading $reading): ?PumpCounterReading
    {
        return PumpCounterReading::where('pump_id', $reading->pump_id)
            ->where(fn ($q) => $q->where('date', '>', $reading->date)
                ->orWhere(fn ($q2) => $q2->where('date', $reading->date)->where('id', '>', $reading->id)))
            ->orderBy('date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Recomputes $reading's liters sold against $prevReading and regenerates its
     * transaction/debt to match, replacing whatever was there before — used to keep a
     * reading's derived figures in sync after the reading that precedes it changes.
     */
    private function recomputeReading(PumpCounterReading $reading, ?PumpCounterReading $prevReading): void
    {
        if ($reading->transaction_id) {
            Transaction::find($reading->transaction_id)?->delete();
        }

        if ($reading->governmental_transaction_id) {
            Transaction::find($reading->governmental_transaction_id)?->delete();
        }

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
