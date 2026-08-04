<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\ExchangeRate;
use App\Models\FuelType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates a realistic 30-day spread of fuel sales, deliveries, other income,
 * and expenses on top of whatever fuel types/tanks/users already exist, so the
 * dashboard (totals, sales chart, by-employee breakdown) can be exercised with
 * real-looking data. Safe to re-run; it only adds new transactions.
 */
class TransactionSeeder extends Seeder
{
    private const DAYS = 30;

    private array $expenseDescriptions = [
        'فاتورة كهرباء',
        'راتب موظف',
        'صيانة معدات',
        'مازوت المولد',
        'فاتورة انترنت',
        'مستلزمات نظافة',
    ];

    private array $incomeDescriptions = [
        'غسيل سيارات',
        'تبديل زيت',
        'مبيعات البقالة',
    ];

    public function run(): void
    {
        $users = User::all();
        $fuelTypes = FuelType::with('tanks')->get();
        $rates = ExchangeRate::all()->keyBy(fn (ExchangeRate $rate) => $rate->currency->value);
        $prices = $fuelTypes->mapWithKeys(fn (FuelType $fuelType) => [
            $fuelType->id => (float) ($fuelType->currentPrice()?->price_per_liter ?? 1.0),
        ]);

        if ($users->isEmpty() || $fuelTypes->isEmpty()) {
            $this->command?->warn('Skipping TransactionSeeder: seed users and fuel types first.');

            return;
        }

        DB::transaction(function () use ($users, $fuelTypes, $rates, $prices) {
            $today = Carbon::now()->startOfDay();

            for ($dayOffset = self::DAYS - 1; $dayOffset >= 0; $dayOffset--) {
                $day = $today->copy()->subDays($dayOffset);

                $this->seedFuelSales($day, $fuelTypes, $users, $rates, $prices);

                if ($dayOffset % 6 === 0) {
                    $this->seedFuelDeliveries($day, $fuelTypes, $users, $prices);
                }

                if ($dayOffset % 5 === 0) {
                    $this->seedOtherIncome($day, $users, $rates);
                }

                if ($dayOffset % 2 === 0) {
                    $this->seedExpense($day, $users, $rates);
                }
            }
        });

        $this->command?->info('Seeded '.self::DAYS.' days of fuel sales, deliveries, income, and expenses.');
    }

    /**
     * @param  Collection<int, FuelType>  $fuelTypes
     * @param  Collection<int, User>  $users
     * @param  Collection<string, ExchangeRate>  $rates
     * @param  \Illuminate\Support\Collection<int, float>  $prices
     */
    private function seedFuelSales(Carbon $day, Collection $fuelTypes, Collection $users, Collection $rates, $prices): void
    {
        $salesCount = random_int(4, 10);

        for ($i = 0; $i < $salesCount; $i++) {
            $fuelType = $fuelTypes->random();
            $tanks = $fuelType->tanks;

            if ($tanks->isEmpty()) {
                continue;
            }

            $tank = $tanks->random();
            $liters = round(random_int(1000, 12000) / 100, 2);
            $currency = $this->randomCurrency();
            [$pricePerLiter, $exchangeRate] = $this->convert($prices[$fuelType->id], $currency, $rates);

            Transaction::create([
                'user_id' => $users->random()->id,
                'type' => TransactionType::FuelSale,
                'fuel_type_id' => $fuelType->id,
                'tank_id' => $tank->id,
                'liters' => $liters,
                'price_per_liter' => $pricePerLiter,
                'amount' => round($liters * $pricePerLiter, 2),
                'currency' => $currency,
                'exchange_rate_to_usd' => $exchangeRate,
                'occurred_at' => $this->randomTimeOn($day),
            ]);
        }
    }

    /**
     * @param  Collection<int, FuelType>  $fuelTypes
     * @param  Collection<int, User>  $users
     * @param  \Illuminate\Support\Collection<int, float>  $prices
     */
    private function seedFuelDeliveries(Carbon $day, Collection $fuelTypes, Collection $users, $prices): void
    {
        foreach ($fuelTypes as $fuelType) {
            if ($fuelType->tanks->isEmpty()) {
                continue;
            }

            $tank = $fuelType->tanks->random();
            $costPerLiter = round($prices[$fuelType->id] * 0.9, 4);
            $liters = random_int(3000, 8000);

            Transaction::create([
                'user_id' => $users->random()->id,
                'type' => TransactionType::FuelDelivery,
                'fuel_type_id' => $fuelType->id,
                'tank_id' => $tank->id,
                'liters' => $liters,
                'price_per_liter' => $costPerLiter,
                'amount' => round($liters * $costPerLiter, 2),
                'currency' => Currency::USD,
                'description' => 'توريد وقود - '.$fuelType->name,
                'occurred_at' => $this->randomTimeOn($day),
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<string, ExchangeRate>  $rates
     */
    private function seedOtherIncome(Carbon $day, Collection $users, Collection $rates): void
    {
        $currency = $this->randomCurrency();
        [$amount, $exchangeRate] = $this->convert(random_int(20, 150), $currency, $rates);

        Transaction::create([
            'user_id' => $users->random()->id,
            'type' => TransactionType::OtherIncome,
            'description' => $this->incomeDescriptions[array_rand($this->incomeDescriptions)],
            'amount' => round($amount, 2),
            'currency' => $currency,
            'exchange_rate_to_usd' => $exchangeRate,
            'occurred_at' => $this->randomTimeOn($day),
        ]);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<string, ExchangeRate>  $rates
     */
    private function seedExpense(Carbon $day, Collection $users, Collection $rates): void
    {
        $currency = $this->randomCurrency();
        [$amount, $exchangeRate] = $this->convert(random_int(10, 300), $currency, $rates);

        Transaction::create([
            'user_id' => $users->random()->id,
            'type' => TransactionType::Expense,
            'description' => $this->expenseDescriptions[array_rand($this->expenseDescriptions)],
            'amount' => round($amount, 2),
            'currency' => $currency,
            'exchange_rate_to_usd' => $exchangeRate,
            'occurred_at' => $this->randomTimeOn($day),
        ]);
    }

    private function randomTimeOn(Carbon $day): Carbon
    {
        return $day->copy()->setTime(random_int(6, 21), random_int(0, 59), random_int(0, 59));
    }

    private function randomCurrency(): Currency
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 55 => Currency::USD,
            $roll <= 85 => Currency::SYP,
            default => Currency::TRY,
        };
    }

    /**
     * @param  Collection<string, ExchangeRate>  $rates
     * @return array{0: float, 1: float|null}
     */
    private function convert(float $usdValue, Currency $currency, Collection $rates): array
    {
        if ($currency === Currency::USD) {
            return [$usdValue, null];
        }

        $rate = (float) ($rates[$currency->value]->rate_to_usd ?? 0);

        if ($rate <= 0) {
            return [$usdValue, null];
        }

        return [round($usdValue * $rate, 4), $rate];
    }
}
