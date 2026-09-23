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
use App\Models\ShopItem;
use App\Models\TankTopUp;
use App\Models\Transaction;
use App\Services\EarningsXlsxExporter;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
     * A color-blocked dashboard sheet built to a specific layout the station requested: a price
     * reference table, then one block per fuel type placed side by side (margin tiers, top-up
     * tiers, subtotal), then Revaluation, Shop Profit, and a Grand Total with its USD equivalent
     * at the current exchange rate -- see EarningsXlsxExporter for why this doesn't reuse the
     * plain stacked-table XlsxTableExporter other pages use. Gated the same way the page itself
     * is (session('earnings_unlocked')) -- the route sits behind role:admin already, but that
     * only proves the requester is an admin, not that they've entered the separate Earnings
     * password this page guards behind.
     */
    public function exportXlsx(Request $request, EarningsXlsxExporter $exporter): HttpResponse
    {
        abort_unless(session('earnings_unlocked'), 403);

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        $sypRate = ExchangeRate::currentRateFor(Currency::SYP);

        [$breakdown, $revaluation, $totalFuelSyp] = $this->fuelTypeBreakdown($from, $to, $sypRate);
        $shopProfit = $this->shopProfitSummary($from, $to, $sypRate);
        $otherExpenseSyp = round($this->otherExpensesSyp($from, $to, $sypRate), 0);
        $totalEarningsSyp = $totalFuelSyp + $shopProfit['net_profit_syp'] - $otherExpenseSyp;

        $isAr = app()->getLocale() === 'ar';
        $l = $isAr ? [
            'title' => 'الأرباح',
            'price_reference' => 'الأسعار الحالية',
            'item' => 'الصنف',
            'price' => 'السعر (ل.س)',
            'price_per_liter_of' => 'سعر لتر ',
            'liters_sold' => 'عدد اللترات المباعة',
            'rate' => 'السعر',
            'liters' => 'اللترات',
            'earnings' => 'الأرباح (ل.س)',
            'margin_earnings' => 'أرباح الهامش',
            'topup_earnings' => 'أرباح الإضافة',
            'subtotal' => 'المجموع الفرعي',
            'revaluation' => 'أرباح فارق السعر',
            'fuel_type' => 'نوع الوقود',
            'old_price' => 'السعر القديم',
            'new_price' => 'السعر الجديد',
            'price_diff' => 'الفارق',
            'profit' => 'الربح',
            'total' => 'الإجمالي',
            'shop_profit' => 'أرباح المتجر',
            'shop_revenue' => 'إجمالي الإيرادات',
            'shop_cogs' => 'تكلفة البضاعة المباعة',
            'net_profit' => 'صافي الربح',
            'qty_sold' => 'الكمية المباعة',
            'cost_per_unit' => 'تكلفة الوحدة',
            'profit_per_unit' => 'ربح الوحدة',
            'total_profit' => 'إجمالي الربح',
            'total_earnings' => 'إجمالي الأرباح',
            'other_expenses' => 'مصاريف أخرى',
            'grand_total' => 'المجموع الكلي',
            'exchange_rate' => 'سعر الصرف',
            'grand_total_usd' => 'المجموع الكلي ($)',
        ] : [
            'title' => 'Earnings',
            'price_reference' => 'Current Prices',
            'item' => 'Item',
            'price' => 'Price (SYP)',
            'price_per_liter_of' => 'Price per liter — ',
            'liters_sold' => 'Liters sold',
            'rate' => 'Rate',
            'liters' => 'Liters',
            'earnings' => 'Earnings (SYP)',
            'margin_earnings' => 'Margin earnings',
            'topup_earnings' => 'Top-up earnings',
            'subtotal' => 'Subtotal',
            'revaluation' => 'Revaluation Profit',
            'fuel_type' => 'Fuel Type',
            'old_price' => 'Old Price',
            'new_price' => 'New Price',
            'price_diff' => 'Price Diff',
            'profit' => 'Profit',
            'total' => 'Total',
            'shop_profit' => 'Shop Profit',
            'shop_revenue' => 'Total Revenue',
            'shop_cogs' => 'Total COGS',
            'net_profit' => 'Net Profit',
            'qty_sold' => 'Qty Sold',
            'cost_per_unit' => 'Cost/Unit',
            'profit_per_unit' => 'Profit/Unit',
            'total_profit' => 'Total Profit',
            'total_earnings' => 'Total earnings',
            'other_expenses' => 'Other expenses',
            'grand_total' => 'Grand Total',
            'exchange_rate' => 'Exchange Rate',
            'grand_total_usd' => 'Grand Total ($)',
        ];

        $priceReference = [];

        foreach (FuelType::with('prices')->orderBy('name')->get() as $fuelType) {
            $currentPrice = $fuelType->currentPrice();

            if ($currentPrice) {
                $priceReference[] = ['label' => $l['price_per_liter_of'].$fuelType->name, 'price_syp' => round($currentPrice->amountInSyp($sypRate), 2)];
            }
        }

        foreach (ShopItem::orderBy('name')->get() as $shopItem) {
            $priceReference[] = ['label' => $shopItem->name, 'price_syp' => round($this->convertToSyp((float) $shopItem->sell_price, $shopItem->currency, $sypRate), 2)];
        }

        return $exporter->download(
            filename: 'earnings-'.$from->toDateString().'-to-'.$to->toDateString().'.xlsx',
            title: $l['title'],
            subtitle: $from->toDateString().' — '.$to->toDateString(),
            priceReference: $priceReference,
            fuelBreakdown: $breakdown,
            revaluation: $revaluation,
            shopProfit: $shopProfit,
            otherExpenseSyp: $otherExpenseSyp,
            totalEarningsSyp: $totalEarningsSyp,
            sypRate: $sypRate,
            labels: $l,
            direction: $isAr ? 'rtl' : 'ltr',
        );
    }

    /**
     * Per fuel type: margin profit tiered exactly like top-up profit below, but attributed by
     * which BATCH a liter physically came from, not which date it sold on -- a liter drawn from
     * legacy (pre-price-change) stock earns that batch's OLD margin rate here, no matter when it
     * actually sells, and the price appreciation on it is reported separately, in full, in the
     * Revaluation card below (see that card's own docs for why splitting it this way is what
     * makes both numbers add up to the liter's true profit with nothing double-counted or lost).
     * Liters from post-change stock (or legacy sales that have no batch to attribute at all --
     * made before this cost engine shipped) are grouped by whichever price actually governed
     * their own sale date, same idea as the top-up tiers below. Each tier's rate is a fixed
     * number for that batch/price (price x margin%) -- never a realized-average that drifts with
     * actual sale amounts, which is what produced a wall of confusing near-duplicate decimals
     * the first time this was tried.
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
            ->with('costAllocations.fuelCostLayer.fuelPrice')
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
            $marginSyp = $priceSyp * ($marginPercent / 100);

            // Margin buckets, keyed so a real FIFO historic layer gets its OWN bucket (its old
            // batch's own margin rate), a Tier-2/no-allocation slice buckets as 'current', and a
            // legacy (no FIFO allocation at all) sale/debt buckets by whichever price governed
            // its own date -- see the class docblock above for why batch origin wins over sale
            // date whenever a real batch is known.
            $marginBuckets = [];

            $addMargin = function (string $key, float $liters, float $rateSyp, ?CarbonInterface $sortKey) use (&$marginBuckets) {
                $marginBuckets[$key] ??= ['liters' => 0.0, 'rate' => $rateSyp, 'sort_key' => $sortKey];
                $marginBuckets[$key]['liters'] += $liters;
            };

            // Revaluation buckets, one per historic layer actually drawn from -- liters, the
            // batch's own old selling price (reverse-derived from the layer's frozen cost basis:
            // cost = old_price x (1 - margin%), so old_price = cost / (1 - margin%); margin% has
            // no historical record, same limitation noted throughout this file, so this uses
            // today's value), and the revenue actually collected for those liters (so the
            // "new price" shown is the true weighted-average price they actually sold at, correct
            // even if a later price change happened while this same layer was still being drawn
            // down).
            $revaluationBuckets = [];

            foreach ($sales as $sale) {
                $saleLiters = (float) $sale->liters;

                if ($saleLiters <= 0) {
                    continue;
                }

                if ($sale->costAllocations->isEmpty()) {
                    // No FIFO allocation recorded for this sale (made before the cost engine
                    // shipped, or through a path that doesn't allocate) -- no batch to attribute,
                    // so fall back to whichever price actually governed its own sale date.
                    $priceAtSale = $this->priceAtSaleFor($fuelType, $sale->occurred_at);
                    $rateSyp = $priceAtSale ? $priceAtSale->amountInSyp($sypRate) * ($marginPercent / 100) : 0.0;
                    $key = $priceAtSale ? 'price_'.$priceAtSale->id : 'price_none';
                    $addMargin($key, $saleLiters, $rateSyp, $priceAtSale?->effective_at);

                    continue;
                }

                $revenuePerLiterSyp = $sale->amountInSyp($sypRate) / $saleLiters;

                foreach ($sale->costAllocations as $allocation) {
                    $sliceLiters = (float) $allocation->liters;

                    if ($allocation->fuel_cost_layer_id === null) {
                        // Tier 2: beyond any historic batch, costed at whatever price was
                        // actually current AT THE MOMENT this allocation was made -- reverse-
                        // derived from its own frozen cost_per_liter_syp, the same way a Tier-1
                        // layer's old rate is derived below, rather than re-reading today's
                        // *current* price. Those can differ: if a further price change has
                        // happened since this allocation was created (live) or since the sale it
                        // covers actually happened (backfilled), re-reading today's price would
                        // silently re-price an old sale at a rate it was never actually sold at.
                        $tier2CostSyp = (float) $allocation->cost_per_liter_syp;
                        $tier2PriceSyp = $marginPercent < 100 ? $tier2CostSyp / (1 - $marginPercent / 100) : 0.0;
                        $tier2RateSyp = $tier2PriceSyp - $tier2CostSyp;

                        $addMargin('tier2_'.round($tier2CostSyp, 4), $sliceLiters, $tier2RateSyp, $allocation->created_at);

                        continue;
                    }

                    $layer = $allocation->fuelCostLayer;
                    $layerCostSyp = (float) $allocation->cost_per_liter_syp;
                    $oldPriceSyp = $marginPercent < 100 ? $layerCostSyp / (1 - $marginPercent / 100) : 0.0;
                    $oldMarginRateSyp = $oldPriceSyp - $layerCostSyp;

                    $addMargin('layer_'.$allocation->fuel_cost_layer_id, $sliceLiters, $oldMarginRateSyp, $layer?->effective_from);

                    $bucketKey = $allocation->fuel_cost_layer_id;
                    $revaluationBuckets[$bucketKey] ??= ['liters' => 0.0, 'old_price_syp' => $oldPriceSyp, 'revenue_syp' => 0.0, 'sort_key' => $layer?->effective_from];
                    $revaluationBuckets[$bucketKey]['liters'] += $sliceLiters;
                    $revaluationBuckets[$bucketKey]['revenue_syp'] += $revenuePerLiterSyp * $sliceLiters;
                }
            }

            // Standalone liters-debts never get FIFO allocations (see
            // FuelCostAllocationService) -- always attributed by their own sale date's price.
            foreach ($debtSales as $debt) {
                $debtLiters = (float) $debt->liters;

                if ($debtLiters <= 0) {
                    continue;
                }

                // $debt->date has no time component (cast as 'date', always midnight) -- resolved
                // as of the END of that day, not the exact midnight instant, so a same-day price
                // change (which now stores its real submission time, not midnight -- see
                // FuelPriceController::resolveEffectiveAt()) is still correctly picked up instead
                // of silently falling back to whatever price was effective the day before.
                $priceAtSale = $this->priceAtSaleFor($fuelType, $debt->date->copy()->endOfDay());
                $rateSyp = $priceAtSale ? $priceAtSale->amountInSyp($sypRate) * ($marginPercent / 100) : 0.0;
                $key = $priceAtSale ? 'price_'.$priceAtSale->id : 'price_none';
                $addMargin($key, $debtLiters, $rateSyp, $priceAtSale?->effective_at);
            }

            // Two buckets can legitimately share the exact same displayed rate -- e.g. a real
            // FIFO layer's old rate and an unrelated Tier-2/legacy bucket that both happen to be
            // priced off the same historical FuelPrice. Shown separately they'd read as a
            // confusing duplicate, so merge any that round to the identical 3-decimal rate into
            // one line -- summing their already-rounded earnings, not re-deriving from a raw
            // sum, so the merge never disturbs the page-wide "every total is a sum of rounded
            // leaves" invariant.
            $marginTiers = collect($marginBuckets)
                ->map(fn (array $bucket) => [
                    'margin_rate_syp' => round($bucket['rate'], 3),
                    'liters' => round($bucket['liters'], 3),
                    'earnings_syp' => (int) round($bucket['liters'] * $bucket['rate'], 0),
                    '_sort_key' => $bucket['sort_key'],
                ])
                // A string key, not the bare float -- Collection::groupBy() uses the group
                // value directly as a PHP array key, and PHP array keys silently TRUNCATE a
                // float to its integer part (a documented, deprecated-but-still-happening
                // behavior). Grouping on the raw float would wrongly merge two genuinely
                // different rates that happen to share the same integer part (4.389 and 4.876
                // would both collapse to key "4").
                ->groupBy(fn (array $tier) => number_format($tier['margin_rate_syp'], 3))
                ->map(fn ($group) => [
                    'margin_rate_syp' => $group->first()['margin_rate_syp'],
                    'liters' => round((float) $group->sum('liters'), 3),
                    'earnings_syp' => (int) $group->sum('earnings_syp'),
                    '_sort_key' => $group->min('_sort_key'),
                ])
                ->sortBy('_sort_key')
                ->values();

            // Volume Sold x (New Price - Old Price) = Revaluation Profit -- the full price
            // appreciation on legacy stock, not reduced by any margin share, since that margin
            // share is already fully and separately credited above via the batch's own old rate.
            // Capped automatically: a bucket's liters can never exceed what
            // FuelCostAllocationService actually allocated against that layer, which itself is
            // capped at the layer's initial_liters snapshot -- once a layer is exhausted, further
            // sales fall to the 'current' margin bucket above and stop contributing here.
            $revaluationTiers = collect($revaluationBuckets)
                ->map(function (array $bucket) {
                    $liters = $bucket['liters'];
                    $newPriceSyp = $liters > 0 ? $bucket['revenue_syp'] / $liters : 0.0;
                    $priceDiffSyp = $newPriceSyp - $bucket['old_price_syp'];

                    return [
                        'liters' => round($liters, 3),
                        'old_price_syp' => round($bucket['old_price_syp'], 2),
                        'new_price_syp' => round($newPriceSyp, 2),
                        'price_diff_syp' => round($priceDiffSyp, 2),
                        'profit_syp' => (int) round($liters * $priceDiffSyp, 0),
                        '_sort_key' => $bucket['sort_key'],
                    ];
                })
                ->sortBy('_sort_key')
                ->values();

            // Sum of the already-rounded per-tier figures, not a separately-rounded raw sum --
            // see the note in index() on why every aggregate here is built this way.
            $revaluationProfitSyp = (int) $revaluationTiers->sum('profit_syp');

            if ($revaluationTiers->isNotEmpty()) {
                $revaluationTotal += $revaluationProfitSyp;
                $revaluationItems[] = [
                    'fuel_type' => ['id' => $fuelType->id, 'name' => $fuelType->name],
                    'tiers' => $revaluationTiers->map(fn (array $tier) => [
                        'liters' => $tier['liters'],
                        'old_price_syp' => $tier['old_price_syp'],
                        'new_price_syp' => $tier['new_price_syp'],
                        'price_diff_syp' => $tier['price_diff_syp'],
                        'profit_syp' => $tier['profit_syp'],
                    ])->all(),
                    'subtotal_syp' => $revaluationProfitSyp,
                ];
            }

            $fuelTopUps = $topUps->filter(fn (TankTopUp $topUp) => $topUp->tank?->fuel_type_id === $fuelType->id);
            $topUpLiters = (float) $fuelTopUps->sum('liters');

            // Grouped by whichever price was actually effective on each top-up's own date, not
            // blanket today's price -- a free/added batch logged weeks ago is worth what it was
            // worth then. A range that spans a price change ends up with more than one tier here,
            // each shown with its own historical price rather than one misleading "current price"
            // figure next to a total that was never computed from it. $topUp->date has no time
            // component (cast as 'date', always midnight), so it's resolved as of the END of that
            // day -- otherwise a same-day price change (stored at its real submission time, not
            // midnight, since FuelPriceController::resolveEffectiveAt()) would look like it hadn't
            // happened yet and every top-up logged that day would wrongly fall back to the
            // previous price.
            $topUpTiers = $fuelTopUps
                ->groupBy(fn (TankTopUp $topUp) => $fuelType->priceAt($topUp->date->copy()->endOfDay())?->id ?? 0)
                ->map(function ($group) use ($fuelType, $sypRate) {
                    $priceAtDate = $fuelType->priceAt($group->first()->date->copy()->endOfDay());
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
            $marginEarningsSyp = (int) $marginTiers->sum('earnings_syp');
            $subtotal = $marginEarningsSyp + $topUpEarningsSyp;
            $total += $subtotal + $revaluationProfitSyp;

            return [
                'fuel_type' => ['id' => $fuelType->id, 'name' => $fuelType->name],
                'liters_sold' => round($litersSold, 3),
                'profit_margin_percent' => round($marginPercent, 4),
                // 3 decimal places, deliberately more precise than every other SYP figure on
                // this page, so the exact per-liter multiplication factor behind each margin
                // tier's earnings is always visible (e.g. 4.542, not a rounded 4.5).
                'profit_margin_syp' => round($marginSyp, 3),
                'margin_tiers' => $marginTiers->map(fn (array $tier) => [
                    'margin_rate_syp' => $tier['margin_rate_syp'],
                    'liters' => $tier['liters'],
                    'earnings_syp' => $tier['earnings_syp'],
                ])->all(),
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
            // limitation as fuel's margin% (see fuelTypeBreakdown above) — so every unit sold in
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
     * The price actually in effect on a historical date -- used to bucket a sale (or standalone
     * liters-debt) into its correct margin tier by whichever price really governed it, not
     * whatever's current now. $fuelType->prices must already be eager-loaded (fuelTypeBreakdown
     * does this once per fuel type rather than re-querying per sale).
     */
    private function priceAtSaleFor(FuelType $fuelType, CarbonInterface $occurredAt): ?FuelPrice
    {
        return $fuelType->prices
            ->filter(fn (FuelPrice $price) => $price->effective_at <= $occurredAt)
            ->sortByDesc('effective_at')
            ->first();
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
