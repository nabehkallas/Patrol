<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\ExchangeRate;
use App\Models\ShopItem;
use App\Models\Transaction;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ShopController extends Controller
{
    public function index(Request $request): Response
    {
        $date = Carbon::parse($request->input('date', today()->toDateString()));

        return Inertia::render('shop/index', [
            'items' => $this->itemOptions(),
            'history' => $this->historyFor($date),
            'date' => $date->toDateString(),
        ]);
    }

    public function exportPdf(Request $request, PdfTableExporter $exporter): HttpResponse
    {
        $date = Carbon::parse($request->input('date', today()->toDateString()));
        $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';

        $entries = Transaction::whereNotNull('shop_item_id')
            ->with(['shopItem', 'user'])
            ->whereDate('occurred_at', $date)
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
            $transaction->type === TransactionType::Expense ? $labels['purchase'] : $labels['sale'],
            $transaction->shopItem?->name ?? '—',
            (string) $transaction->quantity,
            number_format((float) $transaction->amount, 2).' '.$transaction->currency->value,
            $transaction->user?->name ?? '—',
        ])->all();

        return $exporter->download(
            filename: 'shop-'.$date->toDateString().'.pdf',
            title: $labels['title'],
            subtitle: $date->toDateString(),
            headers: [$labels['time'], $labels['type'], $labels['item'], $labels['quantity'], $labels['amount'], $labels['recorded_by']],
            rows: $rows,
            direction: $direction,
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

    private function historyFor(Carbon $date)
    {
        return Transaction::whereNotNull('shop_item_id')
            ->with(['shopItem', 'user'])
            ->whereDate('occurred_at', $date)
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

        $this->recordMovement($request, $data, TransactionType::Expense);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase recorded.')]);

        return to_route('shop.index', ['date' => $data['date']]);
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

        return to_route('shop.index', ['date' => $data['date']]);
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
