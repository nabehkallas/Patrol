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

        [$breakdown, $totalMarginAndTopUpSyp] = $this->fuelTypeBreakdown($from, $to, $sypRate);

        $otherExpenseSyp = $this->otherExpensesSyp($from, $to, $sypRate);

        return Inertia::render('admin/earnings/index', [
            'locked' => false,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'breakdown' => $breakdown,
            'other_expense_syp' => round($otherExpenseSyp, 0),
            'total_earnings_syp' => round($totalMarginAndTopUpSyp - $otherExpenseSyp, 0),
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
     * Per fuel type: (liters sold x profit margin) + (liters topped up for free x current sale
     * price). Returns the breakdown rows plus the combined SYP total across all fuel types.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float}
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
            ->get(['fuel_type_id', 'liters', 'amount', 'currency', 'exchange_rate_to_usd', 'occurred_at']);

        $standaloneDebtSales = Debt::query()
            ->where('direction', DebtDirection::Receivable)
            ->whereNotNull('liters')
            ->whereNull('transaction_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['fuel_type_id', 'liters', 'amount', 'currency', 'exchange_rate_to_usd', 'date']);

        $topUps = TankTopUp::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('tank:id,fuel_type_id')
            ->get(['tank_id', 'liters']);

        $total = 0.0;

        $breakdown = $fuelTypes->map(function (FuelType $fuelType) use ($fuelSales, $standaloneDebtSales, $topUps, $sypRate, &$total) {
            $sales = $fuelSales->where('fuel_type_id', $fuelType->id);
            $debtSales = $standaloneDebtSales->where('fuel_type_id', $fuelType->id);

            $litersSold = (float) $sales->sum('liters') + (float) $debtSales->sum('liters');

            $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);

            // Real profit, sale by sale: what was actually charged minus the fuel type's cost
            // basis on that specific sale's date (its official price then x (1 - margin%)) —
            // not liters x today's margin, so a sale at a custom price, or one made before the
            // official price has since changed, is still accounted for correctly.
            $marginEarningsSyp = $sales->sum(fn (Transaction $sale) => $this->actualProfitSyp(
                $fuelType, $sale->occurred_at, (float) $sale->liters, $sale->amountInSyp($sypRate), $marginPercent, $sypRate,
            )) + $debtSales->sum(fn (Debt $debt) => $this->actualProfitSyp(
                $fuelType, $debt->date, (float) $debt->liters, $debt->amountInSyp($sypRate), $marginPercent, $sypRate,
            ));

            $currentPrice = $fuelType->currentPrice();
            $priceSyp = $currentPrice ? $currentPrice->amountInSyp($sypRate) : 0.0;
            $marginSyp = $priceSyp * ($marginPercent / 100);

            $topUpLiters = (float) $topUps
                ->filter(fn (TankTopUp $topUp) => $topUp->tank?->fuel_type_id === $fuelType->id)
                ->sum('liters');

            $topUpEarningsSyp = $topUpLiters * $priceSyp;

            $subtotal = $marginEarningsSyp + $topUpEarningsSyp;
            $total += $subtotal;

            return [
                'fuel_type' => ['id' => $fuelType->id, 'name' => $fuelType->name],
                'liters_sold' => round($litersSold, 3),
                'profit_margin_percent' => round($marginPercent, 2),
                'profit_margin_syp' => round($marginSyp, 2),
                'margin_earnings_syp' => round($marginEarningsSyp, 0),
                'topup_liters' => round($topUpLiters, 3),
                'price_per_liter_syp' => round($priceSyp, 2),
                'topup_earnings_syp' => round($topUpEarningsSyp, 0),
                'subtotal_syp' => round($subtotal, 0),
            ];
        })->values()->all();

        return [$breakdown, $total];
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
     * Station-wide "other expenses" (plain Expense transactions, excluding Sadcop transfers and
     * anything still tied to an outstanding debt) — same definition used on the Cash Box page.
     */
    private function otherExpensesSyp(CarbonInterface $from, CarbonInterface $to, float $sypRate): float
    {
        return Transaction::query()
            ->where('type', TransactionType::Expense)
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->with(['debt', 'sadcopLedgerEntry'])
            ->get()
            ->reject(fn (Transaction $transaction) => $transaction->sadcopLedgerEntry !== null)
            ->reject(fn (Transaction $transaction) => $transaction->isPendingDebt())
            ->sum(fn (Transaction $transaction) => $transaction->amountInSyp($sypRate));
    }
}
