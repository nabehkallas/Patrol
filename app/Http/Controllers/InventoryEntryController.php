<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\StoreInventoryEntryRequest;
use App\Http\Requests\UpdateInventoryEntryRequest;
use App\Models\InventoryEntry;
use App\Models\PumpCounterReading;
use App\Models\Tank;
use App\Models\TankTopUp;
use App\Models\TankTransfer;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $tanks = Tank::with('fuelType')
            ->orderBy('fuel_type_id')
            ->orderBy('name')
            ->get();

        $from = Carbon::parse($request->input('from', today()->toDateString()));
        $to = Carbon::parse($request->input('to', today()->toDateString()));

        return Inertia::render('inventory/index', [
            'tanks' => $tanks->map(fn (Tank $tank) => $tank->summary()),
            'entries' => InventoryEntry::with(['tank.fuelType', 'recordedBy'])
                ->latest('date')
                ->latest('id')
                ->paginate(25),
            'topUps' => TankTopUp::with(['tank.fuelType', 'recordedBy'])
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->latest('date')
                ->latest('id')
                ->get(),
            'transfers' => TankTransfer::with(['fromTank.fuelType', 'toTank.fuelType', 'recordedBy'])
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->latest('date')
                ->latest('id')
                ->get(),
            'historyFrom' => $from->toDateString(),
            'historyTo' => $to->toDateString(),
        ]);
    }

    public function exportEntriesPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $entries = InventoryEntry::with(['tank.fuelType', 'recordedBy'])->latest('date')->latest('id')->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'سجل المخزون',
            'date' => 'التاريخ',
            'tank' => 'الخزان',
            'quantity' => 'الكمية (لتر)',
            'recorded_by' => 'سجّله',
            'notes' => 'ملاحظات',
        ] : [
            'title' => 'Inventory Entries',
            'date' => 'Date',
            'tank' => 'Tank',
            'quantity' => 'Quantity (L)',
            'recorded_by' => 'Recorded by',
            'notes' => 'Notes',
        ];

        $rows = $entries->map(fn (InventoryEntry $entry) => [
            $entry->date->format('Y-m-d'),
            $entry->tank ? $entry->tank->fuelType?->name.' — '.$entry->tank->name : '—',
            number_format((float) $entry->quantity_liters, 3),
            $entry->recordedBy?->name ?? '—',
            $entry->notes ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'inventory-entries-'.now()->format('Y-m-d').'.pdf',
            title: $labels['title'],
            subtitle: null,
            headers: [$labels['date'], $labels['tank'], $labels['quantity'], $labels['recorded_by'], $labels['notes']],
            rows: $rows,
            direction: $direction,
        );
    }

    public function exportTopUpsPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $from = Carbon::parse($request->input('from', today()->toDateString()));
        $to = Carbon::parse($request->input('to', today()->toDateString()));
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $topUps = TankTopUp::with(['tank.fuelType', 'recordedBy'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->latest('date')
            ->latest('id')
            ->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'التعبئة الفعلية',
            'tank' => 'الخزان',
            'liters' => 'اللترات',
            'recorded_by' => 'سجّله',
            'notes' => 'ملاحظات',
        ] : [
            'title' => 'Tank Top-ups',
            'tank' => 'Tank',
            'liters' => 'Liters',
            'recorded_by' => 'Recorded by',
            'notes' => 'Notes',
        ];

        $rows = $topUps->map(fn (TankTopUp $topUp) => [
            $topUp->tank ? $topUp->tank->fuelType?->name.' — '.$topUp->tank->name : '—',
            number_format((float) $topUp->liters, 3).' L',
            $topUp->recordedBy?->name ?? '—',
            $topUp->notes ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'tank-topups-'.$from->toDateString().'-to-'.$to->toDateString().'.pdf',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: [$labels['tank'], $labels['liters'], $labels['recorded_by'], $labels['notes']],
            rows: $rows,
            direction: $direction,
        );
    }

    /**
     * One side-by-side block per active tank on a single sheet -- each block is its own
     * self-contained daily ledger (Date, Current Balance, Sold, Received, Returned,
     * Differences, Transfer), separated by blank spacer columns. Current Balance at row N is a
     * live formula (row N-1's balance + row N-1's own inflows - row N-1's Sold, e.g.
     * B3=B2+D2+E2+F2+G2-C2) from the third row onward; the second row (the very first day
     * shown) has no earlier row to draw on, so it's seeded as a plain computed number carried
     * in from all history before the report's date range -- that day's own flows only take
     * effect on the *next* row's balance, once they're themselves "the previous row".
     */
    public function exportTanksLedgerXlsx(Request $request): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $tanks = Tank::with('fuelType')
            ->where('is_active', true)
            ->orderBy('fuel_type_id')
            ->orderBy('name')
            ->get();

        $labels = app()->getLocale() === 'ar' ? [
            'sheet' => 'خزانات',
            'title' => 'دفتر جرد الخزانات',
            'date' => 'التاريخ',
            'balance' => 'الموجود بالخزان',
            'sold' => 'المباع',
            'received' => 'الوارد للخزان',
            'returned' => 'مرتجع',
            'differences' => 'فروقات',
            'transfer' => 'تحويل',
            'total' => 'الإجمالي',
        ] : [
            'sheet' => 'Tanks',
            'title' => 'Tank Inventory Ledger',
            'date' => 'Date',
            'balance' => 'Current Balance',
            'sold' => 'Sold',
            'received' => 'Received',
            'returned' => 'Returned',
            'differences' => 'Differences',
            'transfer' => 'Transfer',
            'total' => 'Total',
        ];

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setSize(14);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($labels['sheet'], 0, 31));
        // Always LTR regardless of app locale -- this sheet is for entry/calculation, and an
        // RTL sheet would lay Tank 1's block out on the right with columns counting down,
        // which is not how "Column G separates Tank 1 from Tank 2" reads.
        $sheet->setRightToLeft(false);

        $sheet->setCellValue([1, 1], $labels['title']);
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(16);
        $sheet->setCellValue([1, 2], $from->toDateString().' — '.$to->toDateString());
        $sheet->getStyle([1, 2])->getFont()->setItalic(true);

        $tankNameRow = 4;
        $columnHeaderRow = 5;
        $firstDataRow = 6;
        $spacerWidth = 2; // blank columns between one tank's block and the next

        $days = CarbonPeriod::create($from, $to)->toArray();
        $lastDataRow = $firstDataRow + count($days) - 1;
        $totalsRow = $lastDataRow + 1;

        $col = 1;

        foreach ($tanks as $tank) {
            $col = $this->writeTankLedgerBlock(
                $sheet, $tank, $col, $from, $to, $days,
                $tankNameRow, $columnHeaderRow, $firstDataRow, $lastDataRow, $totalsRow,
                $labels,
            );
            $col += $spacerWidth;
        }

        $lastCol = $col - $spacerWidth - 1;

        if ($lastCol >= 1) {
            $sheet->getColumnDimensionByColumn(1)->setWidth(12);

            for ($c = 2; $c <= $lastCol; $c++) {
                $sheet->getColumnDimensionByColumn($c)->setWidth(14);
            }
        }

        $resource = fopen('php://temp', 'r+');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($resource);
        rewind($resource);
        $contents = stream_get_contents($resource);
        fclose($resource);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="tanks-ledger-'.$from->toDateString().'-to-'.$to->toDateString().'.xlsx"',
        ]);
    }

    /**
     * Writes one tank's block starting at column $startCol and returns the column right after
     * it (i.e. $startCol + block width), so the caller just adds its own spacer width to get
     * the next block's start column.
     *
     * @param  array<int, CarbonInterface>  $days
     */
    private function writeTankLedgerBlock(
        Worksheet $sheet,
        Tank $tank,
        int $startCol,
        CarbonInterface $from,
        CarbonInterface $to,
        array $days,
        int $tankNameRow,
        int $columnHeaderRow,
        int $firstDataRow,
        int $lastDataRow,
        int $totalsRow,
        array $labels,
    ): int {
        $dateCol = $startCol;
        $balanceCol = $startCol + 1;
        $soldCol = $startCol + 2;
        $receivedCol = $startCol + 3;
        $returnedCol = $startCol + 4;
        $differencesCol = $startCol + 5;
        $transferCol = $startCol + 6;

        $balanceLetter = Coordinate::stringFromColumnIndex($balanceCol);
        $soldLetter = Coordinate::stringFromColumnIndex($soldCol);
        $receivedLetter = Coordinate::stringFromColumnIndex($receivedCol);
        $returnedLetter = Coordinate::stringFromColumnIndex($returnedCol);
        $differencesLetter = Coordinate::stringFromColumnIndex($differencesCol);
        $transferLetter = Coordinate::stringFromColumnIndex($transferCol);

        // Grouped once per source, by calendar day, rather than one query per day per source.
        $readingsByDay = PumpCounterReading::where('tank_id', $tank->id)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()->groupBy(fn (PumpCounterReading $r) => $r->date->toDateString());

        $soldByDay = $readingsByDay->map(fn ($rows) => (float) $rows->sum('liters_sold'));
        $returnedByDay = $readingsByDay->map(fn ($rows) => (float) $rows->sum('return_liters'));

        $receivedByDay = Transaction::where('tank_id', $tank->id)
            ->where('type', TransactionType::FuelDelivery)
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->get()->groupBy(fn (Transaction $t) => $t->occurred_at->toDateString())
            ->map(fn ($rows) => (float) $rows->sum('liters'));

        $differencesByDay = TankTopUp::where('tank_id', $tank->id)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()->groupBy(fn (TankTopUp $t) => $t->date->toDateString())
            ->map(fn ($rows) => (float) $rows->sum('liters'));

        $transferInByDay = TankTransfer::where('to_tank_id', $tank->id)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()->groupBy(fn (TankTransfer $t) => $t->date->toDateString())
            ->map(fn ($rows) => (float) $rows->sum('liters'));

        $transferOutByDay = TankTransfer::where('from_tank_id', $tank->id)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()->groupBy(fn (TankTransfer $t) => $t->date->toDateString())
            ->map(fn ($rows) => (float) $rows->sum('liters'));

        // Cumulative balance from all history strictly before $from -- the running total that
        // the first visible row's balance needs to build on, since nothing in the sheet itself
        // precedes it.
        $priorBalance =
            (float) Transaction::where('tank_id', $tank->id)->where('type', TransactionType::FuelDelivery)
                ->where('occurred_at', '<', $from->copy()->startOfDay())->sum('liters')
            + (float) PumpCounterReading::where('tank_id', $tank->id)->whereDate('date', '<', $from)->sum('return_liters')
            + (float) TankTopUp::where('tank_id', $tank->id)->whereDate('date', '<', $from)->sum('liters')
            + (float) TankTransfer::where('to_tank_id', $tank->id)->whereDate('date', '<', $from)->sum('liters')
            - (float) TankTransfer::where('from_tank_id', $tank->id)->whereDate('date', '<', $from)->sum('liters')
            - (float) PumpCounterReading::where('tank_id', $tank->id)->whereDate('date', '<', $from)->sum('liters_sold');

        // Tank name header, merged across the whole block.
        $sheet->setCellValue([$dateCol, $tankNameRow], $tank->fuelType->name.' — '.$tank->name);
        $sheet->mergeCells([$dateCol, $tankNameRow, $transferCol, $tankNameRow]);
        $sheet->getStyle([$dateCol, $tankNameRow])->getFont()->setBold(true)->setSize(15);
        $sheet->getStyle([$dateCol, $tankNameRow])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle([$dateCol, $tankNameRow, $transferCol, $tankNameRow])->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');

        // Column headers.
        $headerValues = [$labels['date'], $labels['balance'], $labels['sold'], $labels['received'], $labels['returned'], $labels['differences'], $labels['transfer']];

        foreach ($headerValues as $i => $value) {
            $sheet->setCellValue([$startCol + $i, $columnHeaderRow], $value);
        }
        $headerRange = [$dateCol, $columnHeaderRow, $transferCol, $columnHeaderRow];
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

        // Data rows. Balance at row N is seeded (row N-1's flows applied to row N-1's own
        // balance) for every row except the very first, which has no earlier row to draw on --
        // it's seeded directly as the cumulative balance from all history before this report's
        // range (that day's own flows only take effect on the *next* row's balance, once
        // they're themselves the "previous row").
        foreach ($days as $i => $day) {
            $row = $firstDataRow + $i;
            $key = $day->toDateString();

            $sold = $soldByDay->get($key, 0.0);
            $returned = $returnedByDay->get($key, 0.0);
            $received = $receivedByDay->get($key, 0.0);
            $differences = $differencesByDay->get($key, 0.0);
            $transfer = $transferInByDay->get($key, 0.0) - $transferOutByDay->get($key, 0.0);

            $sheet->setCellValue([$dateCol, $row], $day->format('d/m/Y'));

            if ($row === $firstDataRow) {
                // No earlier row in the sheet to reference -- seeded as a plain computed number.
                $sheet->setCellValue([$balanceCol, $row], round($priorBalance));
            } else {
                $prevRow = $row - 1;
                $sheet->setCellValue([$balanceCol, $row], "={$balanceLetter}{$prevRow}+{$receivedLetter}{$prevRow}+{$returnedLetter}{$prevRow}+{$differencesLetter}{$prevRow}+{$transferLetter}{$prevRow}-{$soldLetter}{$prevRow}");
            }

            $sheet->setCellValue([$soldCol, $row], round($sold));
            $sheet->setCellValue([$receivedCol, $row], round($received));
            $sheet->setCellValue([$returnedCol, $row], round($returned));
            $sheet->setCellValue([$differencesCol, $row], round($differences));
            $sheet->setCellValue([$transferCol, $row], round($transfer));
        }

        // Totals row -- every column except Balance (a running total, not something to sum).
        $sheet->setCellValue([$dateCol, $totalsRow], $labels['total']);
        $sheet->getStyle([$dateCol, $totalsRow])->getFont()->setBold(true);
        $sheet->setCellValue([$soldCol, $totalsRow], "=SUM({$soldLetter}{$firstDataRow}:{$soldLetter}{$lastDataRow})");
        $sheet->setCellValue([$receivedCol, $totalsRow], "=SUM({$receivedLetter}{$firstDataRow}:{$receivedLetter}{$lastDataRow})");
        $sheet->setCellValue([$returnedCol, $totalsRow], "=SUM({$returnedLetter}{$firstDataRow}:{$returnedLetter}{$lastDataRow})");
        $sheet->setCellValue([$differencesCol, $totalsRow], "=SUM({$differencesLetter}{$firstDataRow}:{$differencesLetter}{$lastDataRow})");
        $sheet->setCellValue([$transferCol, $totalsRow], "=SUM({$transferLetter}{$firstDataRow}:{$transferLetter}{$lastDataRow})");
        $sheet->getStyle([$soldCol, $totalsRow, $transferCol, $totalsRow])->getFont()->setBold(true);
        $sheet->getStyle([$dateCol, $totalsRow, $transferCol, $totalsRow])->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_THIN);

        // All numeric columns (everything but Date) as plain integers, including the totals row.
        $sheet->getStyle([$balanceCol, $firstDataRow, $transferCol, $totalsRow])
            ->getNumberFormat()->setFormatCode('#,##0');

        return $transferCol + 1;
    }

    public function store(StoreInventoryEntryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['recorded_by_id'] = $request->user()->id;

        InventoryEntry::updateOrCreate(
            ['tank_id' => $data['tank_id'], 'date' => $data['date']],
            [
                'quantity_liters' => $data['quantity_liters'],
                'recorded_by_id' => $data['recorded_by_id'],
                'notes' => $data['notes'] ?? null,
            ]
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory recorded.')]);

        return to_route('inventory.index');
    }

    public function editEntry(InventoryEntry $entry): Response
    {
        return Inertia::render('inventory/entry-edit', [
            'entry' => [
                'id' => $entry->id,
                'tank_id' => $entry->tank_id,
                'date' => $entry->date->toDateString(),
                'quantity_liters' => (string) $entry->quantity_liters,
                'notes' => $entry->notes,
            ],
            'tanks' => $this->tankOptions(),
        ]);
    }

    public function updateEntry(UpdateInventoryEntryRequest $request, InventoryEntry $entry): RedirectResponse
    {
        $data = $request->validated();

        // Rule::unique compares the raw input string against the stored column, which is
        // saved with a time component — it would never match, so the duplicate check has to
        // happen here with whereDate() instead of in the FormRequest.
        $duplicate = InventoryEntry::where('tank_id', $data['tank_id'])
            ->whereDate('date', $data['date'])
            ->where('id', '!=', $entry->id)
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['date' => __('An entry for this tank and date already exists.')])->withInput();
        }

        $entry->update([
            'tank_id' => $data['tank_id'],
            'date' => $data['date'],
            'quantity_liters' => $data['quantity_liters'],
            'notes' => $data['notes'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry updated.')]);

        return to_route('inventory.index');
    }

    public function destroyEntry(InventoryEntry $entry): RedirectResponse
    {
        $entry->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry deleted.')]);

        return to_route('inventory.index');
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
                'is_active' => $tank->is_active,
            ]);
    }
}
