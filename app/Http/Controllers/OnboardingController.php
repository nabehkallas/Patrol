<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\DebtStatus;
use App\Enums\SadcopLedgerEntryType;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelPump;
use App\Models\FuelType;
use App\Models\PumpCounterReading;
use App\Models\SadcopLedgerEntry;
use App\Models\Tank;
use App\Models\TankTopUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first-run setup checklist for a freshly provisioned station. Deliberately not a strict
 * linear stepper — every section here is optional (a station can reasonably start at zero
 * everywhere and be corrected later through the normal pages), so all sections are shown at
 * once and "Finish setup" is always available rather than gated behind completing each step.
 */
class OnboardingController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('onboarding/wizard', [
            'sadcopDone' => SadcopLedgerEntry::query()->exists(),
            'tanks' => Tank::with('fuelType')
                ->orderBy('fuel_type_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Tank $tank) => [
                    'id' => $tank->id,
                    'name' => $tank->name,
                    'fuel_type_id' => $tank->fuel_type_id,
                    'fuel_type_name' => $tank->fuelType->name,
                    'has_opening_level' => $tank->topUps()->exists(),
                ]),
            'pumps' => FuelPump::with('fuelTypes')
                ->orderBy('name')
                ->get()
                ->map(fn (FuelPump $pump) => [
                    'id' => $pump->id,
                    'name' => $pump->name,
                    'fuel_type_ids' => $pump->fuelTypes->pluck('id'),
                    'has_reading' => $pump->counterReadings()->exists(),
                ]),
            'fuelTypes' => FuelType::orderBy('name')
                ->get()
                ->map(fn (FuelType $fuelType) => [
                    'id' => $fuelType->id,
                    'name' => $fuelType->name,
                    'has_price' => $fuelType->currentPrice() !== null,
                ]),
            'debts' => Debt::with('debtor')
                ->latest('id')
                ->get()
                ->map(fn (Debt $debt) => [
                    'id' => $debt->id,
                    'debtor_name' => $debt->debtor?->name,
                    'amount' => $debt->amount,
                    'currency' => $debt->currency,
                ]),
        ]);
    }

    public function storeSadcopOpeningBalance(Request $request): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0']]);

        if (SadcopLedgerEntry::query()->doesntExist()) {
            SadcopLedgerEntry::create([
                'type' => SadcopLedgerEntryType::Opening,
                'amount' => $data['amount'],
                'recorded_by_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);
        }

        return back();
    }

    public function storeTankLevels(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'levels' => ['required', 'array'],
            'levels.*.tank_id' => ['required', 'exists:tanks,id'],
            'levels.*.liters' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data['levels'] as $level) {
            if (! empty($level['liters']) && (float) $level['liters'] > 0) {
                TankTopUp::create([
                    'tank_id' => $level['tank_id'],
                    'liters' => $level['liters'],
                    'date' => now()->toDateString(),
                    'recorded_by_id' => $request->user()->id,
                    'notes' => __('Opening balance (initial setup)'),
                ]);
            }
        }

        return back();
    }

    public function storePumpReadings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'readings' => ['required', 'array'],
            'readings.*.pump_id' => ['required', 'exists:fuel_pumps,id'],
            'readings.*.tank_id' => ['required', 'exists:tanks,id'],
            'readings.*.reading_value' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($data['readings'] as $reading) {
            PumpCounterReading::create([
                'pump_id' => $reading['pump_id'],
                'tank_id' => $reading['tank_id'],
                'date' => now()->toDateString(),
                'reading_value' => $reading['reading_value'],
                'recorded_by_id' => $request->user()->id,
                'notes' => __('Initial setup reading'),
            ]);
        }

        return back();
    }

    public function storeFuelPrices(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.fuel_type_id' => ['required', 'exists:fuel_types,id'],
            'prices.*.price_per_liter' => ['required', 'numeric', 'min:0'],
            'prices.*.currency' => ['required', new Enum(Currency::class)],
        ]);

        foreach ($data['prices'] as $price) {
            FuelPrice::create([
                'fuel_type_id' => $price['fuel_type_id'],
                'price_per_liter' => $price['price_per_liter'],
                'currency' => $price['currency'],
                'set_by_id' => $request->user()->id,
                'effective_at' => now(),
            ]);
        }

        return back();
    }

    public function storeDebt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'debtor_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', new Enum(Currency::class)],
            'details' => ['nullable', 'string', 'max:255'],
        ]);

        $debtor = Debtor::firstOrCreate(['name' => $data['debtor_name']]);
        $currency = Currency::from($data['currency']);

        Debt::create([
            'debtor_id' => $debtor->id,
            'amount' => $data['amount'],
            'currency' => $currency,
            'exchange_rate_to_usd' => ExchangeRate::currentRateFor($currency),
            'date' => now()->toDateString(),
            'details' => $data['details'] ?? null,
            'status' => DebtStatus::Outstanding,
            'recorded_by_id' => $request->user()->id,
        ]);

        return back();
    }

    public function finish(): RedirectResponse
    {
        tenant()->update(['onboarded_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Setup complete — welcome in!')]);

        return to_route('cash-box.index');
    }
}
