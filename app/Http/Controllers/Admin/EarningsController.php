<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\DebtDirection;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetupEarningsPasswordRequest;
use App\Http\Requests\Admin\UnlockEarningsRequest;
use App\Models\Debt;
use App\Models\EarningsPassword;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelType;
use App\Models\TankTopUp;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EarningsController extends Controller
{
    public function index(Request $request): Response
    {
        if (! session('earnings_unlocked')) {
            return Inertia::render('admin/earnings/index', [
                'locked' => true,
                'needsSetup' => ! EarningsPassword::isSet(),
            ]);
        }

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        // Every figure below is rounded exactly once, at the point it becomes a displayed leaf
        // value (a tier, a top-up tier, an item's profit, other expenses) -- every aggregate
        // (a card's subtotal, the fuel total, the Grand Total) is then built by summing those
        // already-rounded leaves, never by rounding a separately-accumulated raw sum. That's
        // deliberate: it guarantees the numbers on screen always add up by hand exactly the way
        // a user checking the arithmetic would expect, which summing raw figures and rounding
        // once at the very end cannot guarantee (two leaves can independently round in opposite
        // directions and land the displayed total 1 SYP away from the displayed parts' sum).
        [$breakdown, $totalFuelSyp] = $this->fuelTypeBreakdown($from, $to, $sypRate);

        $shopProfit = $this->shopProfitSummary($from, $to, $sypRate);

        $otherExpenseSyp = round($this->otherExpensesSyp($from, $to, $sypRate), 0);

        return Inertia::render('admin/earnings/index', [
            'locked' => false,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'breakdown' => $breakdown,
            'shop_profit' => $shopProfit,
            'other_expense_syp' => $otherExpenseSyp,
            'total_earnings_syp' => $totalFuelSyp + $shopProfit['net_profit_syp'] - $otherExpenseSyp,
        ]);
    }

    public function unlock(UnlockEarningsRequest $request): RedirectResponse
    {
        if (! EarningsPassword::check($request->validated('password'))) {
            return back()->withErrors(['password' => __('Incorrect password.')])->withInput();
        }

        session(['earnings_unlocked' => true]);

        return to_route('admin.earnings.index');
    }

    public function setup(SetupEarningsPasswordRequest $request): RedirectResponse
    {
        if (EarningsPassword::isSet()) {
            return to_route('admin.earnings.index');
        }

        EarningsPassword::set($request->validated('password'));
        session(['earnings_unlocked' => true]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Earnings password set.')]);

        return to_route('admin.earnings.index');
    }

    /**
     * Per fuel type: FIFO tiered profit on sales (see FuelCostAllocationService) + top-up
     * profit, each top-up valued at the price effective on its own date. Returns the breakdown
     * rows plus the combined (already-rounded) SYP total across all fuel types.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function fuelTypeBreakdown(CarbonInterface $from, CarbonInterface $to, float $sypRate): array
    {
        $fromDt = $from->copy()->startOfDay();
        $toDt = $to->copy()->endOfDay();

        $fuelTypes = FuelType::with('prices')->orderBy('name')->get();

        $fuelSales = Transaction::query()
            ->where('type', TransactionType::FuelSale)
            ->where('occurred_at', '>=', $fromDt)
            ->where('occurred_at', '<=', $toDt)
            ->with('costAllocations')
            ->get(['id', 'fuel_type_id', 'liters', 'amount', 'currency', 'exchange_rate_to_usd', 'occurred_at']);

        $standaloneDebtSales = Debt::query()
            ->where('direction', DebtDirection::Receivable)
            ->whereNotNull('liters')
            ->whereNull('transaction_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['fuel_type_id', 'liters', 'amount', 'currency', 'exchange_rate_to_usd', 'date']);

        // Excludes opening-balance top-ups (a tank's starting inventory, recorded once during
        // onboarding) -- that liters was already in the tank before this reporting period, not
        // fuel added/gained during it, so counting it here would inflate earnings by the full
        // value of the station's starting stock whenever the onboarding date falls in range.
        $topUps = TankTopUp::query()
            ->where('is_opening_balance', false)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('tank:id,fuel_type_id')
            ->get(['tank_id', 'liters', 'date']);

        $total = 0;

        $breakdown = $fuelTypes->map(function (FuelType $fuelType) use ($fuelSales, $standaloneDebtSales, $topUps, $sypRate, &$total) {
            $sales = $fuelSales->where('fuel_type_id', $fuelType->id);
            $debtSales = $standaloneDebtSales->where('fuel_type_id', $fuelType->id);

            $litersSold = (float) $sales->sum('liters') + (float) $debtSales->sum('liters');

            $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);

            $tier1ProfitSyp = 0.0;
            $tier2ProfitSyp = 0.0;
            $legacyProfitSyp = 0.0;

            foreach ($sales as $sale) {
                if ($sale->costAllocations->isEmpty()) {
                    // No FIFO allocation recorded for this sale (made before the cost engine
                    // shipped, or through a path that doesn't allocate) -- fall back to the
                    // estimate: revenue minus the cost basis implied by margin% on the sale's
                    // own date.
                    $legacyProfitSyp += $this->actualProfitSyp(
                        $fuelType, $sale->occurred_at, (float) $sale->liters, $sale->amountInSyp($sypRate), $marginPercent, $sypRate,
                    );

                    continue;
                }

                $revenuePerLiterSyp = (float) $sale->liters > 0
                    ? $sale->amountInSyp($sypRate) / (float) $sale->liters
                    : 0.0;

                foreach ($sale->costAllocations as $allocation) {
                    $sliceProfitSyp = ($revenuePerLiterSyp - (float) $allocation->cost_per_liter_syp) * (float) $allocation->liters;

                    if ($allocation->fuel_cost_layer_id !== null) {
                        // Tier 1: drawn from a historic batch tagged at the cost basis of
                        // whatever price governed before it -- earns the gap between today's
                        // selling price and that old cost, not just the current margin%.
                        $tier1ProfitSyp += $sliceProfitSyp;
                    } else {
                        // Tier 2: beyond any historic batch, costed at the current margin.
                        $tier2ProfitSyp += $sliceProfitSyp;
                    }
                }
            }

            // Standalone liters-debts never get FIFO allocations (see
            // FuelCostAllocationService) -- always the legacy estimate.
            $legacyProfitSyp += $debtSales->sum(fn (Debt $debt) => $this->actualProfitSyp(
                $fuelType, $debt->date, (float) $debt->liters, $debt->amountInSyp($sypRate), $marginPercent, $sypRate,
            ));

            $currentPrice = $fuelType->currentPrice();
            $priceSyp = $currentPrice ? $currentPrice->amountInSyp($sypRate) : 0.0;
            $marginSyp = $priceSyp * ($marginPercent / 100);

            $fuelTopUps = $topUps->filter(fn (TankTopUp $topUp) => $topUp->tank?->fuel_type_id === $fuelType->id);
            $topUpLiters = (float) $fuelTopUps->sum('liters');

            // Grouped by whichever price was actually effective on each top-up's own date, not
            // blanket today's price -- a free/added batch logged weeks ago is worth what it was
            // worth then. A range that spans a price change ends up with more than one tier here,
            // each shown with its own historical price rather than one misleading "current price"
            // figure next to a total that was never computed from it.
            $topUpTiers = $fuelTopUps
                ->groupBy(fn (TankTopUp $topUp) => $fuelType->priceAt($topUp->date)?->id ?? 0)
                ->map(function ($group) use ($fuelType, $sypRate) {
                    $priceAtDate = $fuelType->priceAt($group->first()->date);
                    $tierPriceSyp = $priceAtDate ? $priceAtDate->amountInSyp($sypRate) : 0.0;
                    $tierLiters = (float) $group->sum('liters');

                    return [
                        'price_per_liter_syp' => round($tierPriceSyp, 2),
                        'liters' => round($tierLiters, 3),
                        'earnings_syp' => (int) round($tierLiters * $tierPriceSyp, 0),
                        '_effective_at' => $priceAtDate?->effective_at,
                    ];
                })
                ->sortBy('_effective_at')
                ->values();

            // Sum of the already-rounded per-tier figures, not a separately-rounded raw sum --
            // see the note in index() on why every aggregate here is built this way.
            $topUpEarningsSyp = (int) $topUpTiers->sum('earnings_syp');

            $tier1Syp = (int) round($tier1ProfitSyp, 0);
            // Tier 2 folds in the legacy estimate (sales with no FIFO allocation -- made before
            // the cost engine shipped, or through a path that doesn't allocate): both represent
            // profit at essentially the current margin, with no historic batch involved, so
            // showing them as one number avoids a third, invisible bucket that would otherwise
            // silently eat into margin_earnings_syp without ever appearing as either tier.
            $tier2Syp = (int) round($tier2ProfitSyp + $legacyProfitSyp, 0);
            $marginEarningsSyp = $tier1Syp + $tier2Syp;
            $subtotal = $marginEarningsSyp + $topUpEarningsSyp;
            $total += $subtotal;

            return [
                'fuel_type' => ['id' => $fuelType->id, 'name' => $fuelType->name],
                'liters_sold' => round($litersSold, 3),
                'profit_margin_percent' => round($marginPercent, 4),
                'profit_margin_syp' => round($marginSyp, 2),
                'tier1_profit_syp' => $tier1Syp,
                'tier2_profit_syp' => $tier2Syp,
                'margin_earnings_syp' => $marginEarningsSyp,
                'topup_liters' => round($topUpLiters, 3),
                'price_per_liter_syp' => round($priceSyp, 2),
                'topup_tiers' => $topUpTiers->map(fn (array $tier) => [
                    'price_per_liter_syp' => $tier['price_per_liter_syp'],
                    'liters' => $tier['liters'],
                    'earnings_syp' => $tier['earnings_syp'],
                ])->all(),
                'topup_earnings_syp' => $topUpEarningsSyp,
                'subtotal_syp' => $subtotal,
            ];
        })->values()->all();

        return [$breakdown, $total];
    }

    /**
     * Total shop revenue, COGS, and net profit across every shop item sold in the date filter,
     * plus a per-item breakdown. COGS is unit-cost based -- Quantity Sold x the item's own
     * سعر التكلفة (ShopItem::base_price, the cost price the station enters when it creates or
     * edits the item) -- not reconstructed from Purchase/restock transactions, since those
     * aren't reliably logged for every item and left COGS at 0 whenever they weren't. Every
     * figure here is rounded exactly once, and net_profit_syp/average_margin_percent are built
     * from those already-rounded totals -- see the note in index().
     *
     * @return array{total_revenue_syp: int, total_cogs_syp: int, average_margin_percent: float, net_profit_syp: int, items: array<int, array<string, mixed>>}
     */
    private function shopProfitSummary(CarbonInterface $from, CarbonInterface $to, float $sypRate): array
    {
        $fromDt = $from->copy()->startOfDay();
        $toDt = $to->copy()->endOfDay();

        $sales = Transaction::query()
            ->where('type', TransactionType::OtherIncome)
            ->whereNotNull('shop_item_id')
            ->where('occurred_at', '>=', $fromDt)
            ->where('occurred_at', '<=', $toDt)
            ->with('shopItem')
            ->get(['shop_item_id', 'quantity', 'amount', 'currency', 'exchange_rate_to_usd', 'occurred_at']);

        $totalRevenueSyp = 0;
        $totalCogsSyp = 0;
        $items = [];

        foreach ($sales->groupBy('shop_item_id') as $group) {
            $item = $group->first()->shopItem;

            if (! $item) {
                continue;
            }

            $quantitySold = (int) $group->sum('quantity');
            $revenueSyp = (int) round((float) $group->sum(fn (Transaction $sale) => $sale->amountInSyp($sypRate)), 0);

            // base_price has no historical record — only its current value is ever known, same
            // limitation as fuel's margin% (see actualProfitSyp above) — so every unit sold in
            // the window is costed at today's cost price regardless of when it sold.
            $costPerUnitSyp = round($this->convertToSyp((float) $item->base_price, $item->currency, $sypRate), 2);
            $cogsSyp = (int) round($quantitySold * $costPerUnitSyp, 0);
            $itemProfitSyp = $revenueSyp - $cogsSyp;

            $totalRevenueSyp += $revenueSyp;
            $totalCogsSyp += $cogsSyp;

            $items[] = [
                'id' => $item->id,
                'name' => $item->name,
                'quantity_sold' => $quantitySold,
                'cost_per_unit_syp' => $costPerUnitSyp,
                'profit_per_unit_syp' => round($quantitySold > 0 ? $itemProfitSyp / $quantitySold : 0.0, 2),
                'total_profit_syp' => $itemProfitSyp,
            ];
        }

        usort($items, fn (array $a, array $b) => $b['total_profit_syp'] <=> $a['total_profit_syp']);

        $netProfitSyp = $totalRevenueSyp - $totalCogsSyp;
        $averageMarginPercent = $totalRevenueSyp > 0 ? ($netProfitSyp / $totalRevenueSyp) * 100 : 0.0;

        return [
            'total_revenue_syp' => $totalRevenueSyp,
            'total_cogs_syp' => $totalCogsSyp,
            'average_margin_percent' => round($averageMarginPercent, 2),
            'net_profit_syp' => $netProfitSyp,
            'items' => $items,
        ];
    }

    /**
     * Converts a raw amount in $currency to SYP at CURRENT exchange rates -- for values (like
     * ShopItem::base_price) that aren't attached to a transaction's own recorded
     * exchange_rate_to_usd, so there's no historical rate to use instead.
     */
    private function convertToSyp(float $amount, Currency $currency, float $sypRate): float
    {
        if ($currency === Currency::SYP) {
            return $amount;
        }

        if ($currency === Currency::USD) {
            return $amount * $sypRate;
        }

        $rateToUsd = ExchangeRate::currentRateFor($currency);
        $amountInUsd = $rateToUsd > 0 ? $amount / $rateToUsd : 0.0;

        return $amountInUsd * $sypRate;
    }

    /**
     * Real profit for one sale: actual revenue collected minus the fuel type's cost basis on
     * the date of that specific sale (its official selling price back then x (1 - margin%)).
     * Margin percent itself has no historical record — only the current value is ever known —
     * so it's the one input here that isn't looked up as of the sale's date.
     */
    private function actualProfitSyp(FuelType $fuelType, CarbonInterface $occurredAt, float $liters, float $revenueSyp, float $marginPercent, float $sypRate): float
    {
        if ($liters <= 0) {
            return 0.0;
        }

        $priceAtSale = $fuelType->prices
            ->filter(fn (FuelPrice $price) => $price->effective_at <= $occurredAt)
            ->sortByDesc('effective_at')
            ->first();

        $costPerLiterSyp = $priceAtSale
            ? $priceAtSale->amountInSyp($sypRate) * (1 - $marginPercent / 100)
            : 0.0;

        return $revenueSyp - ($liters * $costPerLiterSyp);
    }

    /**
     * Station-wide "other expenses" (Expense and Purchase transactions, excluding Sadcop
     * transfers and anything still tied to an outstanding debt) — same definition used on the
     * Cash Box page, except a shop restock (a Purchase transaction linked to a shop item) is
     * excluded here specifically: its cost is already recognized once, as COGS, in
     * shopProfitSummary() when the restocked units are sold. Counting it again here as an
     * immediate cash expense would subtract the same cost twice from the Grand Total.
     */
    private function otherExpensesSyp(CarbonInterface $from, CarbonInterface $to, float $sypRate): float
    {
        return Transaction::query()
            ->whereIn('type', [TransactionType::Expense, TransactionType::Purchase])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->with(['debt', 'sadcopLedgerEntry'])
            ->get()
            ->reject(fn (Transaction $transaction) => $transaction->sadcopLedgerEntry !== null)
            ->reject(fn (Transaction $transaction) => $transaction->isPendingDebt())
            ->reject(fn (Transaction $transaction) => $transaction->type === TransactionType::Purchase && $transaction->shop_item_id !== null)
            ->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));
    }
}
