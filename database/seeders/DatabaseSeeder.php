<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelType;
use App\Models\Tank;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Role::firstOrCreate(['name' => UserRole::Admin->value]);
        Role::firstOrCreate(['name' => UserRole::Attendant->value]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@patrol-station.test'],
            ['name' => 'Admin', 'password' => 'password']
        );
        $admin->syncRoles([UserRole::Admin->value]);

        $gasoline = FuelType::firstOrCreate(['slug' => 'gasoline'], ['name' => 'Gasoline']);
        $diesel = FuelType::firstOrCreate(['slug' => 'diesel'], ['name' => 'Diesel']);

        foreach ([$gasoline, $diesel] as $fuelType) {
            if (! $fuelType->currentPrice()) {
                FuelPrice::create([
                    'fuel_type_id' => $fuelType->id,
                    'price_per_liter' => 1.00,
                    'currency' => Currency::USD->value,
                    'set_by_id' => $admin->id,
                    'effective_at' => now(),
                ]);
            }
        }

        foreach ([
            ['fuel_type' => $gasoline, 'name' => 'Tank 1', 'capacity_liters' => 10000],
            ['fuel_type' => $gasoline, 'name' => 'Tank 2', 'capacity_liters' => 10000],
            ['fuel_type' => $diesel, 'name' => 'Tank 1', 'capacity_liters' => 8000],
        ] as $tank) {
            Tank::firstOrCreate(
                ['fuel_type_id' => $tank['fuel_type']->id, 'name' => $tank['name']],
                ['capacity_liters' => $tank['capacity_liters']]
            );
        }

        foreach ([Currency::SYP, Currency::TRY] as $currency) {
            if (ExchangeRate::where('currency', $currency)->doesntExist()) {
                ExchangeRate::create([
                    'currency' => $currency->value,
                    'rate_to_usd' => $currency === Currency::SYP ? 13000 : 32,
                    'set_by_id' => $admin->id,
                    'effective_at' => now(),
                ]);
            }
        }
    }
}
