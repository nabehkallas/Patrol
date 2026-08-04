<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetupEarningsPasswordRequest;
use App\Http\Requests\Admin\UnlockEarningsRequest;
use App\Models\Debt;
use App\Models\EarningsPassword;
use App\Models\ExchangeRate;
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

        $fuelTypes = FuelType::orderBy('name')->get();

        $fuelSaleLiters = Transaction::query()
            ->where('type', TransactionType::FuelSale)
            ->where('occurred_at', '>=', $fromDt)
            ->where('occurred_at', '<=', $toDt)
            ->get(['fuel_type_id', 'liters']);

        $standaloneDebtLiters = Debt::query()
            ->whereNotNull('liters')
            ->whereNull('transaction_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['fuel_type_id', 'liters']);

        $topUps = TankTopUp::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('tank:id,fuel_type_id')
            ->get(['tank_id', 'liters']);

        $total = 0.0;

        $breakdown = $fuelTypes->map(function (FuelType $fuelType) use ($fuelSaleLiters, $standaloneDebtLiters, $topUps, $sypRate, &$total) {
            $litersSold = (float) $fuelSaleLiters->where('fuel_type_id', $fuelType->id)->sum('liters')
                + (float) $standaloneDebtLiters->where('fuel_type_id', $fuelType->id)->sum('liters');

            $currentPrice = $fuelType->currentPrice();
            $priceSyp = $currentPrice ? $currentPrice->amountInSyp($sypRate) : 0.0;

            $marginPercent = (float) ($fuelType->profit_margin_percent ?? 0);
            $marginSyp = $priceSyp * ($marginPercent / 100);
            $marginEarningsSyp = $litersSold * $marginSyp;

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
