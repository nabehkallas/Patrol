<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\StatisticsController;
use App\Models\PumpCounterReading;
use App\Models\Shift;
use App\Models\Tank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnnualFinancialSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The shift migration on a station that already has data: every reading becomes shift 1 and
 * nothing else changes -- no stored row, no figure on any report, and not the order the
 * readings run in (which decides each reading's liters).
 */
class ShiftMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_10_20_000001_create_shifts_table',
        '2026_10_20_000002_add_shift_id_to_pump_counter_readings_table',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);

        $petrol = DB::table('fuel_types')->insertGetId(['name' => 'بنزين', 'slug' => 'petrol', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $diesel = DB::table('fuel_types')->insertGetId(['name' => 'مازوت', 'slug' => 'diesel', 'profit_margin_percent' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $tankA = DB::table('tanks')->insertGetId(['fuel_type_id' => $petrol, 'name' => 'بنزين اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $tankB = DB::table('tanks')->insertGetId(['fuel_type_id' => $petrol, 'name' => 'بنزين احتياطي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $tankD = DB::table('tanks')->insertGetId(['fuel_type_id' => $diesel, 'name' => 'مازوت اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $pump1 = DB::table('fuel_pumps')->insertGetId(['name' => 'بنزين 1', 'created_at' => now(), 'updated_at' => now()]);
        $pump2 = DB::table('fuel_pumps')->insertGetId(['name' => 'مازوت 1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_pump_fuel_type')->insert([['pump_id' => $pump1, 'fuel_type_id' => $petrol], ['pump_id' => $pump2, 'fuel_type_id' => $diesel]]);
        foreach ([[$petrol, 10000], [$diesel, 9000]] as [$fuelType, $price]) {
            DB::table('fuel_prices')->insert(['fuel_type_id' => $fuelType, 'price_per_liter' => $price, 'currency' => 'SYP', 'set_by_id' => $id, 'effective_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fuel_cost_layers')->insert(['fuel_type_id' => $fuelType, 'cost_per_liter_syp' => $price * 0.8, 'currency' => 'SYP', 'initial_liters' => 1500, 'effective_from' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([$tankA, $tankB, $tankD] as $tank) {
            DB::table('tank_top_ups')->insert(['tank_id' => $tank, 'liters' => 10000, 'date' => '2026-10-01', 'recorded_by_id' => $id, 'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        // A price change mid-period, so days are priced differently.
        DB::table('fuel_prices')->insert(['fuel_type_id' => $petrol, 'price_per_liter' => 11000, 'currency' => 'SYP', 'set_by_id' => $id, 'effective_at' => '2026-10-16 00:00:00', 'created_at' => now(), 'updated_at' => now()]);

        // Readings as a real station has them: several in one day on one pump (al-hurria's tank
        // switch), a government sale, and a day entered after later days (out of id order).
        $store = fn (int $pump, int $tank, string $date, int $value, ?float $gov = null) => app(PumpCounterReadingController::class)->store($this->request([
            'pump_id' => $pump, 'tank_id' => $tank, 'date' => $date, 'reading_value' => $value, 'governmental_liters' => $gov,
        ]));
        $store($pump1, $tankA, '2026-10-14', 1000);
        $store($pump1, $tankA, '2026-10-15', 1200);
        $store($pump1, $tankB, '2026-10-15', 1350);
        $store($pump1, $tankA, '2026-10-15', 1400, 30);
        $store($pump1, $tankA, '2026-10-17', 1900);
        $store($pump2, $tankD, '2026-10-14', 5000);
        $store($pump2, $tankD, '2026-10-17', 5600);
        $store($pump1, $tankA, '2026-10-16', 1650);
        $store($pump2, $tankD, '2026-10-15', 5250);

        // Back to the schema before shifts, with the data in it.
        foreach (array_reverse(self::MIGRATIONS) as $name) {
            (require database_path("migrations/tenant/{$name}.php"))->down();
        }
    }

    private function request(array $input): Request
    {
        $request = Request::create('/pump-counters', 'POST', $input);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    private function invokePrivate(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    /** Every stored row the readings feed, without the new shift column. */
    private function rows(): array
    {
        $table = fn (string $name) => DB::table($name)->orderBy('id')->get()
            ->map(fn ($row) => collect((array) $row)->except('shift_id')->all())->all();

        return [
            'readings' => $table('pump_counter_readings'),
            'transactions' => $table('transactions'),
            'allocations' => $table('fuel_cost_allocations'),
            'debts' => $table('debts'),
        ];
    }

    /** Every report figure over the period, with the code as it is now. */
    private function figures(): array
    {
        $from = Carbon::parse('2026-10-01')->startOfDay();
        $to = now()->endOfDay();
        $transactions = Transaction::with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])->get();

        return [
            'cash_box' => $this->invokePrivate(app(CashBoxController::class), 'summarize', $from, $to, true, $this->user->id),
            'cash_box_history' => $this->invokePrivate(app(CashBoxController::class), 'historyEntries', $from, $to, true, $this->user->id),
            'statistics' => $this->invokePrivate(app(StatisticsController::class), 'summarize', $transactions),
            'earnings' => $this->invokePrivate(app(EarningsController::class), 'fuelTypeBreakdown', $from, $to, 'SYP'),
            'annual' => app(AnnualFinancialSummary::class)->forYear(2026),
            'stock' => Tank::orderBy('id')->get()->map(fn (Tank $tank) => $tank->expectedLiters())->all(),
        ];
    }

    /** The readings in the meter's order, and each one's previous value, as the app ran before shifts. */
    private function oldOrder(): array
    {
        $readings = DB::table('pump_counter_readings')->orderBy('pump_id')->orderBy('date')->orderBy('id')->get();
        $previous = [];
        $last = [];

        foreach ($readings as $reading) {
            $previous[$reading->id] = $last[$reading->pump_id] ?? null;
            $last[$reading->pump_id] = (int) $reading->reading_value;
        }

        return ['order' => $readings->pluck('id')->all(), 'previous' => $previous];
    }

    public function test_the_migration_changes_nothing_but_adding_shift_1(): void
    {
        $this->assertFalse(Schema::hasTable('shifts'));
        $rowsBefore = $this->rows();
        $figuresBefore = $this->figures();
        $old = $this->oldOrder();

        foreach (self::MIGRATIONS as $name) {
            (require database_path("migrations/tenant/{$name}.php"))->up();
        }

        // One shift, and every reading in it.
        $this->assertSame(1, Shift::count());
        $shift = Shift::firstOrFail();
        $this->assertSame(1, $shift->position);
        $this->assertSame(PumpCounterReading::count(), PumpCounterReading::where('shift_id', $shift->id)->count());

        // No stored value and no figure changed.
        $this->assertEquals($rowsBefore, $this->rows());
        $this->assertEquals($figuresBefore, $this->figures());

        // The readings run in the same order, so each one still counts from the same reading.
        $newOrder = PumpCounterReading::query()->orderBy('pump_counter_readings.pump_id')->chronological()->pluck('pump_counter_readings.id')->all();
        $this->assertSame($old['order'], $newOrder);

        $controller = app(PumpCounterReadingController::class);
        foreach (PumpCounterReading::all() as $reading) {
            $this->assertSame($old['previous'][$reading->id], $this->invokePrivate($controller, 'previousReadingFor', $reading)?->reading_value, "Reading {$reading->id}");
        }
    }
}
