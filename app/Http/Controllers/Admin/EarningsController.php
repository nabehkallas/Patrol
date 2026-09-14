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
        [$breakdown, $revaluation, $totalFuelSyp] = $this->fuelTypeBreakdown($from, $to, $sypRate);

        $shopProfit = $this->shopProfitSummary($from, $to, $sypRate);

        $otherExpenseSyp = round($this->otherExpensesSyp($from, $to, $sypRate), 0);

        return Inertia::render('admin/earnings/index', [
            'locked' => false,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'breakdown' => $breakdown,
            'revaluation' => $revaluation,
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
     * Per fuel type: a simple, flat operational margin (liters sold x the current fixed margin
     * rate -- no per-sale historical price tiering) + top-up profit, each top-up valued at the
     * price effective on its own date. Any extra profit from selling old, already-owned
     * inventory at a newly raised price is deliberately NOT part of this -- it's a one-time
     * revaluation/windfall gain, not repeatable operational margin, and is reported separately
     * (see the second element of the return tuple, built from the same sales in the same pass).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array{total_syp: int, items: array<int, array<string, mixed>>}, 2: int}
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
            ->with('costAllocations.fuelCostLayer')
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
        $revaluationTotal = 0;
        $revaluationItems = [];

        $breakdown = $fuelTypes->map(function (FuelType $fuelType) use ($fuelSales, $standaloneDebtSales, $topUps, $sypRate, &$total, &$revaluationTotal, &$revaluationItems) {
            $sales = $fuelSales->where('fuel_type_id', $fuelType->id);
            $debtSales = $standaloneDebtSales->where('fuel_type_id', $fuelType->id);

            $litersSold = (float) $sales->sum('liters') + (float) $debtSales->sum('liters');

            $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);

            $currentPrice = $fuelType->currentPrice();
            $priceSyp = $currentPrice ? $currentPrice->amountInSyp($sypRate) : 0.0;
            // Kept at full precision for the actual math below -- only rounded for the
            // 3-decimal-place *display* value, never for the multiplication itself, so a
            // rounded display figure never introduces drift into liters_sold x margin_rate.
            $marginSyp = $priceSyp * ($marginPercent / 100);

            // Operational margin: every liter sold, at ONE fixed rate (today's official
            // margin) -- deliberately not tiered by which price a given liter actually sold
            // under, since that produced a wall of slightly-different per-batch decimal rates
            // that made the card unreadable. See legacyProfitSyp()/FuelCostAllocationService
            // for the OTHER half of the real economics, captured separately below.
            $marginEarningsSyp = $litersSold * $marginSyp;

            // Liters that came from inventory bought/valued before the fuel type's current
            // price took effect -- a real FIFO historic-layer allocation, or a legacy sale
            // (no FIFO allocation at all) priced under a superseded FuelPrice on its own date.
            // Their TRUE profit (revenue minus that old cost basis) is almost always more than
            // the flat margin above already credited them for, because the price rose while
            // they sat in the tank. $revaluationLiters/$revaluationRawProfitSyp accumulate that
            // true profit; the flat-margin share is subtracted out below so this card and the
            // one above never double-count the same liters.
            $revaluationLiters = 0.0;
            $revaluationRawProfitSyp = 0.0;

            foreach ($sales as $sale) {
                $saleLiters = (float) $sale->liters;

                if ($saleLiters <= 0) {
                    continue;
                }

                if ($sale->costAllocations->isEmpty()) {
                    // No FIFO allocation recorded for this sale (made before the cost engine
                    // shipped, or through a path that doesn't allocate) -- only counts toward
                    // revaluation if it was genuinely priced under an OLDER, superseded price.
                    $priceAtSale = $this->priceAtSaleFor($fuelType, $sale->occurred_at);

                    if ($priceAtSale && $currentPrice && $priceAtSale->id !== $currentPrice->id) {
                        $revaluationLiters += $saleLiters;
                        $revaluationRawProfitSyp += $this->legacyProfitSyp($priceAtSale, $saleLiters, $sale->amountInSyp($sypRate), $marginPercent, $sypRate);
                    }

                    continue;
                }

                $revenuePerLiterSyp = $sale->amountInSyp($sypRate) / $saleLiters;

                foreach ($sale->costAllocations as $allocation) {
                    if ($allocation->fuel_cost_layer_id === null) {
                        // Beyond any historic batch, costed at the current margin -- not revaluation.
                        continue;
                    }

                    $sliceLiters = (float) $allocation->liters;
                    $revaluationLiters += $sliceLiters;
                    $revaluationRawProfitSyp += ($revenuePerLiterSyp - (float) $allocation->cost_per_liter_syp) * $sliceLiters;
                }
            }

            // Standalone liters-debts never get FIFO allocations (see
            // FuelCostAllocationService) -- same "only if genuinely under an older price" check.
            foreach ($debtSales as $debt) {
                $debtLiters = (float) $debt->liters;

                if ($debtLiters <= 0) {
                    continue;
                }

                $priceAtSale = $this->priceAtSaleFor($fuelType, $debt->date);

                if ($priceAtSale && $currentPrice && $priceAtSale->id !== $currentPrice->id) {
                    $revaluationLiters += $debtLiters;
                    $revaluationRawProfitSyp += $this->legacyProfitSyp($priceAtSale, $debtLiters, $debt->amountInSyp($sypRate), $marginPercent, $sypRate);
                }
            }

            // The excess over what the flat margin above already credited those same liters
            // for -- purely additive, so Grand Total = margin + revaluation never double-counts.
            $revaluationProfitSyp = (int) round($revaluationRawProfitSyp - ($revaluationLiters * $marginSyp), 0);

            if ($revaluationProfitSyp !== 0) {
                $revaluationTotal += $revaluationProfitSyp;
                $revaluationItems[] = [
                    'fuel_type' => ['id' => $fuelType->id, 'name' => $fuelType->name],
                    'liters' => round($revaluationLiters, 3),
                    'profit_syp' => $revaluationProfitSyp,
                ];
            }

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
            $marginEarningsSypRounded = (int) round($marginEarningsSyp, 0);
            $subtotal = $marginEarningsSypRounded + $topUpEarningsSyp;
            $total += $subtotal + $revaluationProfitSyp;

            return [
                'fuel_type' => ['id' => $fuelType->id, 'name' => $fuelType->name],
                'liters_sold' => round($litersSold, 3),
                'profit_margin_percent' => round($marginPercent, 4),
                // 3 decimal places, deliberately more precise than every other SYP figure on
                // this page, so the exact per-liter multiplication factor behind
                // margin_earnings_syp is always visible (e.g. 4.542, not a rounded 4.5).
                'profit_margin_syp' => round($marginSyp, 3),
                'margin_earnings_syp' => $marginEarningsSypRounded,
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

        return [
            $breakdown,
            ['total_syp' => $revaluationTotal, 'items' => $revaluationItems],
            $total,
        ];
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
            // limitation as fuel's margin% (see legacyProfitSyp below) — so every unit sold in
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
     * The price actually in effect on a historical date -- used to bucket a legacy (no FIFO
     * allocation) sale under whichever price really governed it, not whatever's current now.
     * $fuelType->prices must already be eager-loaded (fuelTypeBreakdown does this once per fuel
     * type rather than re-querying per sale).
     */
    private function priceAtSaleFor(FuelType $fuelType, CarbonInterface $occurredAt): ?FuelPrice
    {
        return $fuelType->prices
            ->filter(fn (FuelPrice $price) => $price->effective_at <= $occurredAt)
            ->sortByDesc('effective_at')
            ->first();
    }

    /**
     * Real profit for one sale with no FIFO allocation: actual revenue collected minus the fuel
     * type's cost basis on the date of that specific sale (its official selling price back then
     * x (1 - margin%)). Margin percent itself has no historical record — only the current value
     * is ever known — so it's the one input here that isn't looked up as of the sale's date.
     */
    private function legacyProfitSyp(?FuelPrice $priceAtSale, float $liters, float $revenueSyp, float $marginPercent, float $sypRate): float
    {
        if ($liters <= 0) {
            return 0.0;
        }

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
