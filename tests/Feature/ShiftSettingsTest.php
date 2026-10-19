<?php

namespace Tests\Feature;

use App\Http\Controllers\Settings\ShiftController;
use App\Models\PumpCounterReading;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Settings > Shifts: positions never change, and a station always keeps an active shift. */
class ShiftSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function send(string $method, array $input = [], ?Shift $shift = null): void
    {
        $controller = app(ShiftController::class);
        $request = Request::create('/settings/shifts', 'POST', $input);

        match ($method) {
            'store' => $controller->store($request),
            'update' => $controller->update($request, $shift),
            'destroy' => $controller->destroy($shift),
        };
    }

    public function test_every_station_starts_with_one_unnamed_shift(): void
    {
        $this->assertSame(1, Shift::count());
        $this->assertSame('Shift 1', Shift::defaultShift()->label());
    }

    public function test_a_new_shift_goes_at_the_end(): void
    {
        $this->send('store', ['name' => 'مسائية', 'starts_at' => '14:00', 'ends_at' => '22:00']);
        $this->send('store', ['name' => '', 'starts_at' => '22:00', 'ends_at' => '06:00']);

        $this->assertSame([1, 2, 3], Shift::orderBy('position')->pluck('position')->all());
        $this->assertSame('مسائية', Shift::where('position', 2)->value('name'));
        $this->assertNull(Shift::where('position', 3)->value('name'));
    }

    public function test_the_last_active_shift_cannot_be_turned_off_or_deleted(): void
    {
        $only = Shift::defaultShift();

        $this->expectException(ValidationException::class);
        $this->send('update', ['is_active' => false], $only);
    }

    public function test_a_shift_with_readings_can_only_be_deactivated(): void
    {
        $this->send('store', ['name' => 'مسائية']);
        $evening = Shift::where('position', 2)->firstOrFail();
        $user = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $pump = DB::table('fuel_pumps')->insertGetId(['name' => 'P', 'created_at' => now(), 'updated_at' => now()]);
        PumpCounterReading::create(['pump_id' => $pump, 'shift_id' => $evening->id, 'date' => '2026-10-15', 'reading_value' => 100, 'recorded_by_id' => $user]);

        try {
            $this->send('destroy', [], $evening);
            $this->fail('A shift with readings was deleted.');
        } catch (ValidationException) {
            $this->assertTrue(Shift::whereKey($evening->id)->exists());
        }

        $this->send('update', ['name' => 'مسائية', 'is_active' => false], $evening);
        $this->assertFalse($evening->fresh()->is_active);
    }

    public function test_an_unused_shift_can_be_deleted(): void
    {
        $this->send('store', ['name' => 'مؤقتة']);

        $this->send('destroy', [], Shift::where('position', 2)->firstOrFail());

        $this->assertSame(1, Shift::count());
    }
}
