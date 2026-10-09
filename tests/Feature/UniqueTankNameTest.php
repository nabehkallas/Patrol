<?php

namespace Tests\Feature;

use App\Rules\UniqueTankName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** A fuel type can't have two tanks with the same name; another fuel type can reuse it. */
class UniqueTankNameTest extends TestCase
{
    use RefreshDatabase;

    private function fuelType(string $slug): int
    {
        return DB::table('fuel_types')->insertGetId(['name' => $slug, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function tank(int $fuelTypeId, string $name): int
    {
        return DB::table('tanks')->insertGetId(['fuel_type_id' => $fuelTypeId, 'name' => $name, 'capacity_liters' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function passes(string $name, int $fuelTypeId, ?int $ignore = null): bool
    {
        return Validator::make(['name' => $name], ['name' => [new UniqueTankName($fuelTypeId, $ignore)]])->passes();
    }

    public function test_the_same_name_is_refused_under_one_fuel_type_but_allowed_under_another(): void
    {
        $petrol = $this->fuelType('petrol');
        $diesel = $this->fuelType('diesel');
        $tank = $this->tank($petrol, 'Tank 1');

        $this->assertFalse($this->passes('Tank 1', $petrol));
        $this->assertFalse($this->passes('  tank 1 ', $petrol), 'Case and spaces do not make a new name');
        $this->assertTrue($this->passes('Tank 1', $diesel));
        $this->assertTrue($this->passes('Tank 1', $petrol, ignore: $tank), 'Saving a tank under its own name');
        $this->assertTrue($this->passes('Tank 2', $petrol));
    }

    public function test_the_message_is_translated(): void
    {
        $this->fuelType('petrol');
        $this->tank(1, 'خزان 1');
        app()->setLocale('ar');

        $errors = Validator::make(['name' => 'خزان 1'], ['name' => [new UniqueTankName(1)]])->errors();

        $this->assertSame('يوجد خزان بهذا الاسم لنفس نوع الوقود. اختر اسماً مختلفاً.', $errors->first('name'));
    }
}
