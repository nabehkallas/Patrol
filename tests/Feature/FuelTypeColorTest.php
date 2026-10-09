<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreFuelTypeRequest;
use App\Models\FuelType;
use App\Support\FuelColors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each fuel type keeps its own colour: new ones take the next free colour, and none ever shift. */
class FuelTypeColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_fuel_types_take_the_next_free_colour_and_keep_it(): void
    {
        $petrol = FuelType::create(['name' => 'Petrol', 'slug' => 'petrol']);
        $diesel = FuelType::create(['name' => 'Diesel', 'slug' => 'diesel']);
        $gas = FuelType::create(['name' => 'Gas', 'slug' => 'gas']);

        $this->assertSame(['amber', 'blue', 'emerald'], [$petrol->color, $diesel->color, $gas->color]);

        // Removing one never moves the others; its colour is free again for the next new one.
        $diesel->delete();
        $this->assertSame('amber', $petrol->fresh()->color);
        $this->assertSame('emerald', $gas->fresh()->color);
        $this->assertSame('blue', FuelType::create(['name' => 'Kerosene', 'slug' => 'kerosene'])->color);
    }

    public function test_a_chosen_colour_is_kept_and_only_palette_colours_are_accepted(): void
    {
        $this->assertSame('rose', FuelType::create(['name' => 'Petrol', 'slug' => 'petrol', 'color' => 'rose'])->color);
        $this->assertSame('amber', FuelColors::nextFree(), 'The first free colour is still amber');

        $rules = (new StoreFuelTypeRequest)->rules();
        $this->assertTrue(validator(['name' => 'X', 'color' => 'blue'], ['color' => $rules['color']])->passes());
        $this->assertFalse(validator(['name' => 'X', 'color' => 'pink-ish'], ['color' => $rules['color']])->passes());
    }

    public function test_when_every_colour_is_taken_the_least_used_comes_next(): void
    {
        foreach (FuelColors::PALETTE as $i => $color) {
            FuelType::create(['name' => "F{$i}", 'slug' => "f{$i}"]);
        }

        $this->assertSame('amber', FuelColors::nextFree());
        FuelType::create(['name' => 'Extra', 'slug' => 'extra']);
        $this->assertSame('blue', FuelColors::nextFree());
    }
}
