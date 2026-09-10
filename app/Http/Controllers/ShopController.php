<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\ExchangeRate;
use App\Models\ShopItem;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use App\Services\XlsxTableExporter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ShopController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        return Inertia::render('shop/index', [
            'items' => $this->itemOptions(),
            'history' => $this->historyFor($from, $to),
            'quantitiesSold' => $this->quantitiesSoldFor($from, $to),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $entries = Transaction::whereNotNull('shop_item_id')
            ->with(['shopItem', 'user'])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->latest('occurred_at')
            ->latest('id')
            ->get();

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'سجل المتجر',
            'time' => 'الوقت',
            'type' => 'النوع',
            'item' => 'الصنف',
            'quantity' => 'الكمية',
            'amount' => 'المبلغ',
            'recorded_by' => 'سجّله',
            'purchase' => 'شراء',
            'sale' => 'بيع',
        ] : [
            'title' => 'Shop History',
            'time' => 'Time',
            'type' => 'Type',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'amount' => 'Amount',
            'recorded_by' => 'Recorded by',
            'purchase' => 'Purchase',
            'sale' => 'Sale',
        ];

        $rows = $entries->map(fn (Transaction $transaction) => [
            $transaction->occurred_at->format('H:i'),
            $transaction->type === TransactionType::Purchase ? $labels['purchase'] : $labels['sale'],
            $transaction->shopItem?->name ?? '—',
            (string) $transaction->quantity,
            number_format((float) $transaction->amount, 2).' '.$transaction->currency->value,
            $transaction->user?->name ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'shop-'.$from->toDateString().'-to-'.$to->toDateString().'.pdf',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: [$labels['time'], $labels['type'], $labels['item'], $labels['quantity'], $labels['amount'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
        );
    }

    /**
     * One row per day: for every shop item, in its own group of columns, the stock level as of
     * that day, how many units sold that day, and the resulting revenue (sold x the item's
     * current unit price, a live formula) -- plus an overall revenue total per currency at the
     * end (a shop can carry items priced in different currencies, so a single grand total would
     * silently mix them). Stock is a real historical reconstruction (units purchased minus units
     * sold, up to and including that day), not today's currentStock() repeated on every row --
     * seeded from all movement strictly before the range, the same pattern used for the running
     * totals in the Sadcop/Cash Box/Pump Counters exports.
     */
    public function exportXlsx(Request $request, XlsxTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $items = ShopItem::orderBy('name')->get();

        $sumQtyBefore = fn (TransactionType $type) => Transaction::whereNotNull('shop_item_id')
            ->where('type', $type)
            ->where('occurred_at', '<', $from->copy()->startOfDay())
            ->groupBy('shop_item_id')
            ->selectRaw('shop_item_id, SUM(quantity) as qty')
            ->pluck('qty', 'shop_item_id');

        $priorPurchased = $sumQtyBefore(TransactionType::Purchase);
        $priorSold = $sumQtyBefore(TransactionType::OtherIncome);

        $runningStock = $items->mapWithKeys(fn (ShopItem $item) => [
            $item->id => (int) ($priorPurchased[$item->id] ?? 0) - (int) ($priorSold[$item->id] ?? 0),
        ])->all();

        $transactions = Transaction::whereNotNull('shop_item_id')
            ->whereIn('type', [TransactionType::Purchase, TransactionType::OtherIncome])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->get();

        $byDayItem = fn (TransactionType $type) => $transactions->where('type', $type)
            ->groupBy(fn (Transaction $t) => $t->occurred_at->toDateString().'|'.$t->shop_item_id);

        $purchasedByDayItem = $byDayItem(TransactionType::Purchase);
        $soldByDayItem = $byDayItem(TransactionType::OtherIncome);

        $labels = app()->getLocale() === 'ar' ? [
            'title' => 'المتجر',
            'date' => 'التاريخ',
            'stock' => 'المخزون الحالي',
            'sold' => 'الكمية المباعة',
            'revenue' => 'إجمالي السعر',
            'overall' => 'إجمالي الإيراد اليومي',
        ] : [
            'title' => 'Shop',
            'date' => 'Date',
            'stock' => 'Current Stock',
            'sold' => 'Sold Qty',
            'revenue' => 'Total Revenue',
            'overall' => 'Overall Daily Revenue',
        ];

        // Column indexes are 1-based here (matching Coordinate::stringFromColumnIndex) for
        // building cell-reference formulas; converted to 0-based when writing into $row/$headerRow.
        $headerRow = [$labels['date']];
        $soldColByItem = [];
        $revenueColByItem = [];
        $revenueColsByCurrency = [];
        $col = 1;

        foreach ($items as $item) {
            $currency = $item->currency->value;

            $headerRow[] = null;
            $headerRow[] = $item->name.' — '.$labels['stock'];
            $headerRow[] = $item->name.' — '.$labels['sold'];
            $headerRow[] = $item->name.' — '.$labels['revenue'].' ('.$currency.')';

            $soldColByItem[$item->id] = $col + 3;
            $revenueColByItem[$item->id] = $col + 4;
            $revenueColsByCurrency[$currency][] = $col + 4;
            $col += 4;
        }

        $currencies = $items->pluck('currency.value')->unique()->sort()->values();
        $overallColByCurrency = [];

        foreach ($currencies as $currency) {
            $headerRow[] = $labels['overall'].' ('.$currency.')';
            $overallColByCurrency[$currency] = ++$col;
        }

        $columnLetter = fn (int $index) => Coordinate::stringFromColumnIndex($index);

        $rows = [];
        $firstDataRow = 5; // title, subtitle, blank, header, then data
        $columnFormats = [];

        foreach (CarbonPeriod::create($from, $to) as $i => $day) {
            $dayKey = $day->toDateString();
            $thisRow = $firstDataRow + $i;

            $row = [$dayKey];

            foreach ($items as $item) {
                $purchasedToday = (int) ($purchasedByDayItem->get("{$dayKey}|{$item->id}", collect())->sum('quantity'));
                $soldToday = (int) ($soldByDayItem->get("{$dayKey}|{$item->id}", collect())->sum('quantity'));
                $runningStock[$item->id] += $purchasedToday - $soldToday;

                $soldCol = $columnLetter($soldColByItem[$item->id]);

                $row[] = null;
                $row[] = $runningStock[$item->id];
                $row[] = $soldToday > 0 ? $soldToday : null;
                $row[] = "={$soldCol}{$thisRow}*".((float) $item->sell_price);

                $columnFormats[$soldColByItem[$item->id] - 2] = '#,##0'; // stock column
                $columnFormats[$soldColByItem[$item->id] - 1] = '#,##0';
                $columnFormats[$revenueColByItem[$item->id] - 1] = '#,##0.00';
            }

            foreach ($currencies as $currency) {
                $cells = implode(',', array_map(fn ($c) => $columnLetter($c).$thisRow, $revenueColsByCurrency[$currency]));
                $row[] = "=SUM({$cells})";
                $columnFormats[$overallColByCurrency[$currency] - 1] = '#,##0.00';
            }

            $rows[] = $row;
        }

        return $exporter->download(
            filename: 'shop-'.$from->toDateString().'-to-'.$to->toDateString().'.xlsx',
            title: $labels['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            headers: $headerRow,
            rows: $rows,
            direction: 'ltr',
            columnFormats: $columnFormats,
        );
    }

    private function itemOptions()
    {
        return ShopItem::orderBy('name')->get()->map(fn (ShopItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'stock' => $item->currentStock(),
            'base_price' => $item->base_price,
            'sell_price' => $item->sell_price,
            'currency' => $item->currency->value,
        ]);
    }

    private function historyFor(CarbonInterface $from, CarbonInterface $to)
    {
        return Transaction::whereNotNull('shop_item_id')
            ->with(['shopItem', 'user'])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->latest('occurred_at')
            ->latest('id')
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'item_name' => $transaction->shopItem?->name ?? '—',
                'quantity' => $transaction->quantity,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency->value,
                'occurred_at' => $transaction->occurred_at,
                'recorded_by' => $transaction->user?->name,
                'notes' => $transaction->notes,
            ]);
    }

    /**
     * Quantity sold of each item within the given range — sales only (type OtherIncome),
     * purchases/restocking don't count as "sold".
     */
    private function quantitiesSoldFor(CarbonInterface $from, CarbonInterface $to)
    {
        return Transaction::whereNotNull('shop_item_id')
            ->where('type', TransactionType::OtherIncome)
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->with('shopItem')
            ->get()
            ->groupBy('shop_item_id')
            ->map(fn ($group) => [
                'id' => $group->first()->shop_item_id,
                'name' => $group->first()->shopItem?->name ?? '—',
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->sortBy('name')
            ->values();
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:shop_items,name'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'in:SYP,TRY,USD'],
        ]);

        ShopItem::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop item created.')]);

        return to_route('shop.index');
    }

    public function updateItem(Request $request, ShopItem $shopItem): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:shop_items,name,'.$shopItem->id],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'in:SYP,TRY,USD'],
        ]);

        $shopItem->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop item updated.')]);

        return to_route('shop.index');
    }

    public function destroyItem(ShopItem $shopItem): RedirectResponse
    {
        if ($shopItem->transactions()->exists()) {
            return back()->withErrors(['item' => __('This item has transaction history and cannot be deleted.')]);
        }

        $shopItem->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop item deleted.')]);

        return to_route('shop.index');
    }

    public function storePurchase(Request $request): RedirectResponse
    {
        $data = $this->validateMovement($request);

        $this->recordMovement($request, $data, TransactionType::Purchase);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase recorded.')]);

        return to_route('shop.index');
    }

    public function storeSale(Request $request): RedirectResponse
    {
        $data = $this->validateMovement($request);

        $item = ShopItem::findOrFail($data['shop_item_id']);
        $stock = $item->currentStock();

        if ($data['quantity'] > $stock) {
            throw ValidationException::withMessages([
                'quantity' => __('This exceeds the current stock (:stock).', ['stock' => $stock]),
            ]);
        }

        $this->recordMovement($request, $data, TransactionType::OtherIncome);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sale recorded.')]);

        return to_route('shop.index');
    }

    private function validateMovement(Request $request): array
    {
        return $request->validate([
            'shop_item_id' => ['required', 'exists:shop_items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'in:SYP,TRY,USD'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function recordMovement(Request $request, array $data, TransactionType $type): void
    {
        $item = ShopItem::findOrFail($data['shop_item_id']);
        $currency = Currency::from($data['currency']);

        Transaction::create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'shop_item_id' => $item->id,
            'quantity' => $data['quantity'],
            'description' => $item->name.' × '.$data['quantity'],
            'amount' => $data['amount'],
            'currency' => $currency,
            'exchange_rate_to_usd' => ExchangeRate::currentRateFor($currency),
            'occurred_at' => Carbon::parse($data['date'])->setTimeFrom(now()),
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
