<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Models\FuelCostAllocation;
use App\Models\PumpCounterReading;
use App\Models\Shift;
use App\Models\Tank;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Readings belong to a date + shift. A shift's sales count from the reading before it: the
 * previous shift that day, or the previous day's last shift. Past shifts can be entered or
 * edited later, with the same recalculation of the following reading as for days.
 */
class ShiftReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $tankId;

    private int $pumpId;

    /** @var array<int, Shift> by position */
    private array $shifts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        Role::findOrCreate('admin', 'web');
        $this->user->assignRole('admin');

        $fuelType = DB::table('fuel_types')->insertGetId(['name' => 'بنزين', 'slug' => 'petrol', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->tankId = DB::table('tanks')->insertGetId(['fuel_type_id' => $fuelType, 'name' => 'بنزين اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $this->pumpId = DB::table('fuel_pumps')->insertGetId(['name' => 'بنزين 1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_pump_fuel_type')->insert(['pump_id' => $this->pumpId, 'fuel_type_id' => $fuelType]);
        DB::table('fuel_prices')->insert(['fuel_type_id' => $fuelType, 'price_per_liter' => 10000, 'currency' => 'SYP', 'set_by_id' => $id, 'effective_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_cost_layers')->insert(['fuel_type_id' => $fuelType, 'cost_per_liter_syp' => 8000, 'currency' => 'SYP', 'initial_liters' => 20000, 'effective_from' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tank_top_ups')->insert(['tank_id' => $this->tankId, 'liters' => 20000, 'date' => '2026-10-01', 'recorded_by_id' => $id, 'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Three shifts: the migration's first one, plus an evening and a night shift.
        $this->shifts[1] = Shift::defaultShift();
        $this->shifts[1]->update(['name' => 'صباحية', 'starts_at' => '06:00', 'ends_at' => '14:00']);
        $this->shifts[2] = Shift::create(['position' => 2, 'name' => 'مسائية', 'starts_at' => '14:00', 'ends_at' => '22:00']);
        $this->shifts[3] = Shift::create(['position' => 3, 'name' => 'ليلية', 'starts_at' => '22:00', 'ends_at' => '06:00']);
    }

    private function request(array $input): Request
    {
        $request = Request::create('/pump-counters', 'POST', $input);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    private function enter(string $date, int $shift, int $value): void
    {
        app(PumpCounterReadingController::class)->storeBulk($this->request([
            'date' => $date, 'shift_id' => $this->shifts[$shift]->id,
            'readings' => [['pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'reading_value' => $value]],
        ]));
    }

    private function reading(string $date, int $shift): PumpCounterReading
    {
        return PumpCounterReading::whereDate('date', $date)->where('shift_id', $this->shifts[$shift]->id)->latest('id')->firstOrFail();
    }

    /** Liters sold by each reading, in the meter's order: "date/shift" => liters. */
    private function liters(): array
    {
        return PumpCounterReading::with('shift')->chronological()->get()
            ->mapWithKeys(fn (PumpCounterReading $r) => [$r->date->format('d').'/'.$r->shift->position => $r->liters_sold !== null ? (float) $r->liters_sold : null])
            ->all();
    }

    private function totals(): array
    {
        $sales = Transaction::where('type', 'fuel_sale')->get();
        $summary = (new ReflectionMethod(CashBoxController::class, 'summarize'))
            ->invoke(app(CashBoxController::class), Carbon::parse('2026-10-15'), Carbon::parse('2026-10-18')->endOfDay(), true, $this->user->id);

        return [
            'liters' => round((float) $sales->sum('liters'), 3),
            'amount' => round((float) $sales->sum('amount'), 2),
            'cost' => round((float) FuelCostAllocation::get()->sum(fn ($a) => $a->liters * $a->cost_per_liter_syp), 2),
            'stock' => Tank::findOrFail($this->tankId)->expectedLiters(),
            'cash_box' => $summary,
        ];
    }

    private function assertSalesMatchReadings(): void
    {
        foreach (PumpCounterReading::all() as $reading) {
            $sale = $reading->transaction_id ? Transaction::find($reading->transaction_id) : null;
            $this->assertSame($reading->liters_sold !== null, $sale !== null);

            if ($sale) {
                $this->assertSame(round((float) $reading->liters_sold, 3), round((float) $sale->liters, 3));
                $this->assertSame($reading->date->toDateString(), $sale->occurred_at->toDateString());
            }
        }

        $this->assertSame(PumpCounterReading::whereNotNull('transaction_id')->count(), Transaction::where('type', 'fuel_sale')->count());
    }

    public function test_each_shift_counts_from_the_shift_before_it(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 2, 1200);
        $this->enter('2026-10-15', 3, 1500);
        $this->enter('2026-10-16', 1, 1700);

        // The next day's first shift counts from the previous day's last (night) shift.
        $this->assertSame(['15/1' => null, '15/2' => 200.0, '15/3' => 300.0, '16/1' => 200.0], $this->liters());
        // The night shift's sale stays on its starting day.
        $this->assertSame('2026-10-15', Transaction::find($this->reading('2026-10-15', 3)->transaction_id)->occurred_at->toDateString());
        $this->assertSalesMatchReadings();
    }

    public function test_a_missed_shift_entered_later_splits_the_next_shifts_sale(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 3, 1500);
        $this->enter('2026-10-16', 1, 1700);
        $before = $this->totals();

        // The evening shift was forgotten and entered after the night shift and the next day.
        $this->enter('2026-10-15', 2, 1200);

        $this->assertSame(['15/1' => null, '15/2' => 200.0, '15/3' => 300.0, '16/1' => 200.0], $this->liters());
        $this->assertSalesMatchReadings();
        $this->assertEquals($before, $this->totals());
    }

    public function test_a_shift_value_outside_the_shifts_around_it_is_rejected(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 3, 1500);

        try {
            $this->enter('2026-10-15', 2, 1600);
            $this->fail('The reading was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1,000', $e->errors()['readings.0.reading_value'][0]);
            $this->assertStringContainsString('1,500', $e->errors()['readings.0.reading_value'][0]);
        }

        $this->assertSame(2, PumpCounterReading::count());
    }

    public function test_a_second_reading_in_the_same_shift_follows_the_first(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 2, 1200);
        $this->enter('2026-10-15', 3, 1500);
        // A tank switch during the evening shift, entered afterwards.
        $this->enter('2026-10-15', 2, 1300);

        $evening = PumpCounterReading::where('shift_id', $this->shifts[2]->id)->orderBy('id')->get();
        $this->assertSame([200.0, 100.0], $evening->pluck('liters_sold')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(200.0, (float) $this->reading('2026-10-15', 3)->liters_sold);
        $this->assertSalesMatchReadings();
    }

    public function test_editing_a_shift_recalculates_the_next_shift(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 2, 1200);
        $this->enter('2026-10-15', 3, 1500);
        $before = $this->totals();
        $reading = $this->reading('2026-10-15', 2);

        app(PumpCounterReadingController::class)->update($this->request([
            'pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'date' => '2026-10-15',
            'shift_id' => $this->shifts[2]->id, 'reading_value' => 1350,
        ]), $reading);

        $this->assertSame(['15/1' => null, '15/2' => 350.0, '15/3' => 150.0], $this->liters());
        $this->assertSalesMatchReadings();
        $this->assertEquals($before, $this->totals());
    }

    public function test_moving_a_reading_to_another_shift_recalculates_both_neighbours(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 3, 1500);
        $this->enter('2026-10-16', 1, 1700);
        // Recorded under the night shift, but it was really the evening one; then the real
        // night reading is added.
        app(PumpCounterReadingController::class)->update($this->request([
            'pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'date' => '2026-10-15',
            'shift_id' => $this->shifts[2]->id, 'reading_value' => 1500,
        ]), $this->reading('2026-10-15', 3));
        $this->enter('2026-10-15', 3, 1600);

        $this->assertSame(['15/1' => null, '15/2' => 500.0, '15/3' => 100.0, '16/1' => 100.0], $this->liters());
        $this->assertSalesMatchReadings();
    }

    public function test_deleting_a_shift_reading_gives_its_liters_to_the_next_shift(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 2, 1200);
        $this->enter('2026-10-15', 3, 1500);
        $before = $this->totals();

        app(PumpCounterReadingController::class)->destroy($this->reading('2026-10-15', 2));

        $this->assertSame(['15/1' => null, '15/3' => 500.0], $this->liters());
        $this->assertSalesMatchReadings();
        $this->assertEquals($before, $this->totals());
    }

    public function test_the_entry_table_shows_what_is_saved_in_that_date_and_shift(): void
    {
        $this->enter('2026-10-15', 1, 1000);
        $this->enter('2026-10-15', 3, 1500);

        $entry = fn (int $shift) => (new ReflectionMethod(PumpCounterReadingController::class, 'entryContext'))
            ->invoke(app(PumpCounterReadingController::class), '2026-10-15', $this->shifts[$shift], true)['pumps'][0];

        // The evening shift has nothing yet, and sits between the morning and night readings.
        $this->assertSame([], $entry(2)['recorded']);
        $this->assertSame(1000, $entry(2)['previous']['reading_value']);
        $this->assertSame(1500, $entry(2)['next']['reading_value']);
        // The night shift is recorded; another value there would follow its 1,500.
        $this->assertSame([1500], array_column($entry(3)['recorded'], 'reading_value'));
        $this->assertSame(1500, $entry(3)['previous']['reading_value']);
        $this->assertNull($entry(3)['next']);
    }

    public function test_going_back_to_an_earlier_shift_counts_from_the_reading_just_before_it(): void
    {
        $this->enter('2026-10-12', 1, 1000);
        $this->enter('2026-10-13', 1, 1500);
        $this->enter('2026-10-20', 1, 5000);

        $context = fn () => (new ReflectionMethod(PumpCounterReadingController::class, 'entryContext'))
            ->invoke(app(PumpCounterReadingController::class), '2026-10-12', $this->shifts[2], true);

        // The 12 Oct evening shift counts from that morning's 1,000, never the pump's newest 5,000.
        $this->assertSame(1000, $context()['pumps'][0]['previous']['reading_value']);
        $this->assertSame(1500, $context()['pumps'][0]['next']['reading_value']);
        $this->assertSame([], $context()['totals']);

        $this->enter('2026-10-12', 2, 1200);

        // 13 Oct now counts from 1,200.
        $thirteenth = $this->reading('2026-10-13', 1);
        $this->assertSame(300.0, (float) $thirteenth->liters_sold);
        $previousOf = new ReflectionMethod(PumpCounterReadingController::class, 'previousReadingFor');
        $this->assertSame(1200, $previousOf->invoke(app(PumpCounterReadingController::class), $thirteenth)->reading_value);
        $this->assertSame(['12/1' => null, '12/2' => 200.0, '13/1' => 300.0, '20/1' => 3500.0], $this->liters());
        $this->assertSalesMatchReadings();

        // The entry table shows the saved evening reading with what it counted from and sold,
        // and the chips that shift's sales.
        $saved = $context()['pumps'][0]['recorded'];
        $this->assertSame([['reading_value' => 1200, 'previous_value' => 1000, 'liters_sold' => 200.0]], array_map(
            fn (array $r) => ['reading_value' => $r['reading_value'], 'previous_value' => $r['previous_value'], 'liters_sold' => (float) $r['liters_sold']],
            $saved,
        ));
        $this->assertSame(1200, $context()['pumps'][0]['previous']['reading_value']);
        $this->assertSame(200.0, $context()['totals'][0]['liters_sold']);
        $this->assertSame(0.0, $context()['totals'][0]['governmental_liters']);
    }

    /**
     * "Save, then switch" in the entry table saves the typed values to the date + shift they
     * were typed for -- the one in the form, not today or the default shift -- and comes back
     * there; the table then moves on to the new date + shift with empty inputs.
     */
    public function test_saving_before_a_switch_goes_to_the_date_and_shift_the_values_were_typed_for(): void
    {
        $response = app(PumpCounterReadingController::class)->storeBulk($this->request([
            'date' => '2026-10-15', 'shift_id' => $this->shifts[2]->id,
            'readings' => [['pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'reading_value' => 1000, 'notes' => 'مسائية']],
        ]));

        $saved = PumpCounterReading::sole();
        $this->assertSame('2026-10-15', $saved->date->toDateString());
        $this->assertSame($this->shifts[2]->id, $saved->shift_id);
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame(['from' => '2026-10-15', 'to' => '2026-10-15', 'entry_date' => '2026-10-15', 'entry_shift' => (string) $this->shifts[2]->id], $query);

        // The day it's switched to has nothing of it.
        $next = (new ReflectionMethod(PumpCounterReadingController::class, 'entryContext'))
            ->invoke(app(PumpCounterReadingController::class), '2026-10-16', $this->shifts[1], true);
        $this->assertSame([], $next['pumps'][0]['recorded']);
        $this->assertSame([], $next['totals']);
        $this->assertSame(1000, $next['pumps'][0]['previous']['reading_value']);
    }

    public function test_a_reading_saved_without_a_shift_goes_to_the_first_shift(): void
    {
        app(PumpCounterReadingController::class)->store($this->request([
            'pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'date' => '2026-10-15', 'reading_value' => 1000,
        ]));

        $this->assertSame($this->shifts[1]->id, PumpCounterReading::firstOrFail()->shift_id);
    }
}
