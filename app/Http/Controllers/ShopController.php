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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
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
        [$itemId, $type] = $this->logFilters($request);

        // The totals cards follow the type filter: purchases when filtering to purchases,
        // otherwise sales (the "All" view keeps showing what was sold, as before).
        $summaryType = $type ?? TransactionType::OtherIncome;

        return Inertia::render('shop/index', [
            'items' => $this->itemOptions(),
            'categories' => ShopItem::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'history' => $this->historyFor($from, $to, $itemId, $type),
            'itemTotals' => $this->itemTotalsFor($from, $to, $itemId, $summaryType),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'shop_item_id' => $itemId,
                'type' => match ($type) {
                    TransactionType::Purchase => 'purchase',
                    TransactionType::OtherIncome => 'sale',
                    default => null,
                },
            ],
            'summaryType' => $summaryType === TransactionType::Purchase ? 'purchase' : 'sale',
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();
        [$itemId, $type] = $this->logFilters($request);
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $entries = $this->logQuery($from, $to, $itemId, $type)
            ->with(['shopItem', 'user'])
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

        // Today's price for each item, applied for the whole exported range -- same
        // pre-existing "current price blanket-applied to the range" behavior this export has
        // always had, just sourced from the price history table instead of a plain column now.
        $currentPriceByItem = $items->mapWithKeys(fn (ShopItem $item) => [$item->id => $item->currentPrice()]);

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
            $currency = $currentPriceByItem[$item->id]?->currency->value ?? Currency::SYP->value;

            $headerRow[] = null;
            $headerRow[] = $item->name.' — '.$labels['stock'];
            $headerRow[] = $item->name.' — '.$labels['sold'];
            $headerRow[] = $item->name.' — '.$labels['revenue'].' ('.$currency.')';

            $soldColByItem[$item->id] = $col + 3;
            $revenueColByItem[$item->id] = $col + 4;
            $revenueColsByCurrency[$currency][] = $col + 4;
            $col += 4;
        }

        $currencies = $items->map(fn (ShopItem $item) => $currentPriceByItem[$item->id]?->currency->value ?? Currency::SYP->value)->unique()->sort()->values();
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
                $sellPrice = (float) ($currentPriceByItem[$item->id]?->sell_price ?? 0);
                $row[] = "={$soldCol}{$thisRow}*".$sellPrice;

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
        return ShopItem::orderBy('name')->get()->map(function (ShopItem $item) {
            $price = $item->currentPrice();

            return [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'stock' => $item->currentStock(),
                'base_price' => $price?->base_price,
                'sell_price' => $price?->sell_price,
                'currency' => $price?->currency->value ?? Currency::SYP->value,
            ];
        });
    }

    /**
     * The log's optional item/type filters, validated. Type arrives as "purchase"/"sale" and is
     * mapped to the transaction type it means (a shop sale is stored as OtherIncome).
     *
     * @return array{0: ?int, 1: ?TransactionType}
     */
    private function logFilters(Request $request): array
    {
        $filters = $request->validate([
            'shop_item_id' => ['nullable', 'integer', 'exists:shop_items,id'],
            'type' => ['nullable', 'in:purchase,sale'],
        ]);

        $type = match ($filters['type'] ?? null) {
            'purchase' => TransactionType::Purchase,
            'sale' => TransactionType::OtherIncome,
            default => null,
        };

        return [isset($filters['shop_item_id']) ? (int) $filters['shop_item_id'] : null, $type];
    }

    /**
     * Shop movements in the date range, narrowed by the optional item/type filters -- the single
     * query behind the log table, its totals cards and the PDF export, so they always agree.
     */
    private function logQuery(CarbonInterface $from, CarbonInterface $to, ?int $itemId, ?TransactionType $type): Builder
    {
        return Transaction::whereNotNull('shop_item_id')
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->when($itemId, fn (Builder $query) => $query->where('shop_item_id', $itemId))
            ->when($type, fn (Builder $query) => $query->where('type', $type));
    }

    private function historyFor(CarbonInterface $from, CarbonInterface $to, ?int $itemId, ?TransactionType $type)
    {
        return $this->logQuery($from, $to, $itemId, $type)
            ->with(['shopItem', 'user'])
            ->latest('occurred_at')
            ->latest('id')
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'shop_item_id' => $transaction->shop_item_id,
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
     * Per-item quantity for one movement type (sales or purchases) within the range, narrowed
     * by the item filter.
     */
    private function itemTotalsFor(CarbonInterface $from, CarbonInterface $to, ?int $itemId, TransactionType $type)
    {
        return $this->logQuery($from, $to, $itemId, $type)
            ->with('shopItem')
            ->get()
            ->groupBy('shop_item_id')
            ->map(fn ($group) => [
                'id' => $group->first()->shop_item_id,
                'name' => $group->first()->shopItem?->name ?? '—',
                'category' => $group->first()->shopItem?->category,
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->sortBy('name')
            ->values();
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:shop_items,name'],
            'category' => ['nullable', 'string', 'max:100'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'in:SYP,TRY,USD'],
        ]);

        DB::transaction(function () use ($request, $data) {
            $shopItem = ShopItem::create(['name' => $data['name'], 'category' => $data['category'] ?? null]);

            $shopItem->prices()->create([
                'base_price' => $data['base_price'],
                'sell_price' => $data['sell_price'],
                'currency' => $data['currency'],
                'set_by_id' => $request->user()->id,
                'effective_at' => now(),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shop item created.')]);

        return to_route('shop.index');
    }

    public function updateItem(Request $request, ShopItem $shopItem): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:shop_items,name,'.$shopItem->id],
            'category' => ['nullable', 'string', 'max:100'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'in:SYP,TRY,USD'],
            'effective_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'effective_at.before_or_equal' => __('The effective date cannot be in the future.'),
        ]);

        $repriced = 0;

        DB::transaction(function () use ($request, $data, $shopItem, &$repriced) {
            $shopItem->update(['name' => $data['name'], 'category' => $data['category'] ?? null]);

            $currentPrice = $shopItem->currentPrice();

            // Only a name-typo fix, nothing priced actually changed -- don't pollute the price
            // history (or trigger a reprice pass) for an edit that has no bearing on price.
            // Compared as floats, not strings: base_price/sell_price are decimal-cast ("120.00"),
            // so a raw request value ("120") would otherwise always look different even when
            // it's the exact same price.
            $priceChanged = ! $currentPrice
                || (float) $currentPrice->base_price !== (float) $data['base_price']
                || (float) $currentPrice->sell_price !== (float) $data['sell_price']
                || $currentPrice->currency->value !== $data['currency'];

            if (! $priceChanged) {
                return;
            }

            $effectiveAt = $this->resolveEffectiveAt($data['effective_at'] ?? null, now());

            $shopItem->prices()->create([
                'base_price' => $data['base_price'],
                'sell_price' => $data['sell_price'],
                'currency' => $data['currency'],
                'set_by_id' => $request->user()->id,
                'effective_at' => $effectiveAt,
            ]);

            $repriced = $this->repriceShopSales($shopItem, $effectiveAt);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->withRepriceNote(__('Shop item updated.'), $repriced)]);

        return to_route('shop.index');
    }

    /**
     * The date field on this form has no time component, so a submission dated TODAY doesn't
     * actually tell us "effective from midnight" -- it means "effective starting right now".
     * Copied from FuelPriceController::resolveEffectiveAt() -- same reasoning: only an explicit
     * BACKDATE (a date strictly before today) anchors to startOfDay(); a same-day submission
     * resolves to the real now() timestamp, so a same-day price correction can't accidentally
     * reprice a sale that happened earlier today but before the correction was actually entered.
     */
    private function resolveEffectiveAt(?string $submittedDate, CarbonInterface $noDateFallback): CarbonInterface
    {
        if (! $submittedDate) {
            return $noDateFallback;
        }

        $date = Carbon::parse($submittedDate);

        return $date->isSameDay(now()) ? now() : $date->startOfDay();
    }

    /**
     * Rewrites amount on every sale (never purchase -- see ShopItem's docs) of $shopItem
     * occurring on or after $from, to match whatever sell price is correctly effective as of
     * each sale's own date. Mirrors FuelPriceController::repriceTransactions() exactly: quantity
     * never changes, so this updates rows in place rather than deleting/recreating them.
     */
    private function repriceShopSales(ShopItem $shopItem, CarbonInterface $from): int
    {
        $sales = Transaction::where('shop_item_id', $shopItem->id)
            ->where('type', TransactionType::OtherIncome)
            ->where('occurred_at', '>=', $from)
            ->get();

        $repriced = 0;

        foreach ($sales as $sale) {
            $priceAtDate = $shopItem->priceAt($sale->occurred_at);
            $newAmount = $priceAtDate ? round((float) $sale->quantity * (float) $priceAtDate->sell_price, 2) : 0.0;

            if ($newAmount === (float) $sale->amount) {
                continue;
            }

            $sale->update(['amount' => $newAmount]);
            $repriced++;
        }

        return $repriced;
    }

    private function withRepriceNote(string $message, int $repriced): string
    {
        if ($repriced === 0) {
            return $message;
        }

        return $message.' '.__(':count transaction(s) updated to match.', ['count' => $repriced]);
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

    /**
     * Corrects a past purchase or sale record in place -- quantity, item, date, amount, currency,
     * notes -- rather than requiring a delete-and-recreate. Never changes $transaction->type: that
     * would flip which direction it moves stock, a materially different (and riskier) operation
     * than fixing a mistaken quantity or date.
     */
    public function updateTransaction(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        $data = $this->validateMovement($request);
        $item = ShopItem::findOrFail($data['shop_item_id']);

        if ($transaction->type === TransactionType::OtherIncome) {
            $stock = $item->currentStock();

            // The old quantity is about to be replaced, not stacked on top -- if this edit keeps
            // the same item, its own current contribution to "sold" must be added back before
            // checking the new quantity fits, otherwise every edit would appear to exceed stock
            // by this transaction's own old quantity.
            if ($transaction->shop_item_id === $item->id) {
                $stock += $transaction->quantity;
            }

            if ($data['quantity'] > $stock) {
                throw ValidationException::withMessages([
                    'quantity' => __('This exceeds the current stock (:stock).', ['stock' => $stock]),
                ]);
            }
        }

        $transaction->update([
            'shop_item_id' => $item->id,
            'quantity' => $data['quantity'],
            'description' => $item->name.' × '.$data['quantity'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'exchange_rate_to_usd' => ExchangeRate::currentRateFor(Currency::from($data['currency'])),
            'occurred_at' => Carbon::parse($data['date'])->setTimeFrom(now()),
            'notes' => $data['notes'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction updated.')]);

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
