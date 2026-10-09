<?php

namespace App\Http\Controllers;

use App\Concerns\GroupsByCurrency;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Debt;
use App\Models\ExchangeRate;
use App\Models\FuelPump;
use App\Models\FuelType;
use App\Models\PumpCounterReading;
use App\Models\Tank;
use App\Models\Transaction;
use App\Support\Currency;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The station's day on one screen, and where everyone lands after signing in: which pumps still
 * need their counter reading, money in and out, tank levels, what is owed, and the prices and
 * rates in force — with anything that needs attention listed first.
 *
 * "The day" follows the user's default entry date: someone who closes the previous day's shift
 * after midnight (Settings > Preferences, "yesterday") sees yesterday.
 */
class TodayController extends Controller
{
    use GroupsByCurrency;

    /** A tank below this share of its capacity is flagged as running low. */
    private const LOW_TANK_SHARE = 0.15;

    public function __invoke(Request $request): Response
    {
        $day = $request->user()->default_entry_date === 'yesterday'
            ? now()->subDay()->toDateString()
            : now()->toDateString();

        $recordedPumpIds = PumpCounterReading::whereDate('date', $day)->pluck('pump_id')->unique();
        $pumps = FuelPump::orderBy('name')->get(['id', 'name'])
            ->map(fn (FuelPump $pump) => [
                'id' => $pump->id,
                'name' => $pump->name,
                'recorded' => $recordedPumpIds->contains($pump->id),
            ])
            ->values();

        $transactions = Transaction::with('debt')
            ->whereDate('occurred_at', $day)
            ->get();
        $moneyIn = $transactions
            ->whereIn('type', [TransactionType::FuelSale, TransactionType::OtherIncome])
            ->reject(fn (Transaction $t) => $t->isPendingDebt());
        $moneyOut = $transactions->whereIn('type', [TransactionType::Expense, TransactionType::Purchase]);

        $tanks = Tank::with('fuelType')->where('is_active', true)->orderBy('name')->get()
            ->map(function (Tank $tank) {
                $liters = $tank->expectedLiters();
                $capacity = (float) $tank->capacity_liters;

                return [
                    'id' => $tank->id,
                    'name' => $tank->name,
                    'fuel_type' => $tank->fuelType->name,
                    'liters' => round($liters, 3),
                    'capacity' => $capacity,
                    'state' => match (true) {
                        $liters < -0.0005 => 'negative',
                        $capacity > 0 && $liters / $capacity < self::LOW_TANK_SHARE => 'low',
                        default => 'ok',
                    },
                ];
            })
            ->values();

        $outstanding = fn (DebtDirection $direction) => $this->byCurrency(
            Debt::with('payments')->where('status', DebtStatus::Outstanding)->where('direction', $direction)->get(),
            fn (Debt $debt) => $debt->remainingAmount(),
        );

        $prices = FuelType::orderBy('name')->get()
            ->map(fn (FuelType $fuelType) => [
                'fuel_type' => $fuelType->name,
                'price' => $fuelType->currentPrice()?->only(['price_per_liter', 'currency']),
            ])
            ->values();

        $rates = collect(Currency::codes())
            ->reject(fn (string $code) => $code === Currency::USD)
            ->map(fn (string $code) => [
                'currency' => $code,
                'rate' => ExchangeRate::where('currency', $code)->exists() ? ExchangeRate::currentRateFor($code) : null,
            ])
            ->values();

        return Inertia::render('today', [
            'day' => $day,
            'pumps' => $pumps,
            'money' => [
                'in' => $this->byCurrency($moneyIn),
                'out' => $this->byCurrency($moneyOut),
                'count' => $transactions->count(),
            ],
            'tanks' => $tanks,
            'debts' => [
                'receivable' => $outstanding(DebtDirection::Receivable),
                'payable' => $outstanding(DebtDirection::Payable),
            ],
            'prices' => $prices,
            'rates' => $rates,
        ]);
    }
}
