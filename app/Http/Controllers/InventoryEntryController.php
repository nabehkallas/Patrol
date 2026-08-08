<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryEntryRequest;
use App\Models\InventoryEntry;
use App\Models\Tank;
use App\Models\TankTopUp;
use App\Models\TankTransfer;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $tanks = Tank::with('fuelType')
            ->orderBy('fuel_type_id')
            ->orderBy('name')
            ->get();

        $topUpDate = Carbon::parse($request->input('topup_date', today()->toDateString()));

        return Inertia::render('inventory/index', [
            'tanks' => $tanks->map(fn (Tank $tank) => $tank->summary()),
            'entries' => InventoryEntry::with(['tank.fuelType', 'recordedBy'])
                ->latest('date')
                ->paginate(25),
            'topUps' => TankTopUp::with(['tank.fuelType', 'recordedBy'])
                ->whereDate('date', $topUpDate)
                ->latest('id')
                ->get(),
            'transfers' => TankTransfer::with(['fromTank.fuelType', 'toTank.fuelType', 'recordedBy'])
                ->whereDate('date', $topUpDate)
                ->latest('id')
                ->get(),
            'topUpDate' => $topUpDate->toDateString(),
        ]);
    }

    public function exportEntriesPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $entries = InventoryEntry::with(['tank.fuelType', 'recordedBy'])->latest('date')->get();

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
        $topUpDate = Carbon::parse($request->input('topup_date', today()->toDateString()));
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $topUps = TankTopUp::with(['tank.fuelType', 'recordedBy'])
            ->whereDate('date', $topUpDate)
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
            filename: 'tank-topups-'.$topUpDate->toDateString().'.pdf',
            title: $labels['title'],
            subtitle: $topUpDate->toDateString(),
            headers: [$labels['tank'], $labels['liters'], $labels['recorded_by'], $labels['notes']],
            rows: $rows,
            direction: $direction,
        );
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
}
