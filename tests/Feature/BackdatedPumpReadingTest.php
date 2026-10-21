<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\StatisticsController;
use App\Models\FuelCostAllocation;
use App\Models\PumpCounterReading;
use App\Models\Tank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FuelCostAllocationService;
use App\Services\PdfTableExporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A reading forgotten on a past day can be added (or a past one edited) after later days were
 * already entered. The value must sit between the readings around it, and the reading after it
 * is recalculated: its liters, its sale at its own day's price, and the fuel cost drawn. Liters
 * and cost across the affected days stay the same; only how they split between the days moves.
 */
class BackdatedPumpReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $fuelTypeId;

    private int $tankId;

    private int $pumpId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        Role::findOrCreate('admin', 'web');
        $this->user->assignRole('admin');

        $this->fuelTypeId = DB::table('fuel_types')->insertGetId(['name' => 'مازوت', 'slug' => 'diesel', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->tankId = DB::table('tanks')->insertGetId(['fuel_type_id' => $this->fuelTypeId, 'name' => 'مازوت اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $this->pumpId = DB::table('fuel_pumps')->insertGetId(['name' => 'مازوت 1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_pump_fuel_type')->insert(['pump_id' => $this->pumpId, 'fuel_type_id' => $this->fuelTypeId]);

        $this->price(10000, '2026-10-01 00:00:00');
        // 20,000 L in stock, bought at 8,000 a liter.
        DB::table('fuel_cost_layers')->insert(['fuel_type_id' => $this->fuelTypeId, 'cost_per_liter_syp' => 8000, 'currency' => 'SYP', 'initial_liters' => 20000, 'effective_from' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tank_top_ups')->insert(['tank_id' => $this->tankId, 'liters' => 20000, 'date' => '2026-10-01', 'recorded_by_id' => $id, 'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now()]);

        // 15 Oct, 17 Oct and 18 Oct were entered; 16 Oct was forgotten.
        $this->store('2026-10-15', 1000);
        $this->store('2026-10-17', 1500);
        $this->store('2026-10-18', 1800);
    }

    private function price(float $price, string $from): void
    {
        DB::table('fuel_prices')->insert(['fuel_type_id' => $this->fuelTypeId, 'price_per_liter' => $price, 'currency' => 'SYP', 'set_by_id' => $this->user->id, 'effective_at' => $from, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function request(array $input): Request
    {
        $request = Request::create('/pump-counters', 'POST', $input);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    private function store(string $date, int $value): void
    {
        app(PumpCounterReadingController::class)->store($this->request([
            'pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'date' => $date, 'reading_value' => $value,
        ]));
    }

    /** The forgotten day, entered through the entry table with a past date selected. */
    private function storeBulk(string $date, int $value): void
    {
        app(PumpCounterReadingController::class)->storeBulk($this->request([
            'date' => $date,
            'readings' => [['pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'reading_value' => $value]],
        ]));
    }

    private function update(PumpCounterReading $reading, array $changes): void
    {
        app(PumpCounterReadingController::class)->update($this->request([
            'pump_id' => $reading->pump_id, 'tank_id' => $reading->tank_id, 'date' => $reading->date->toDateString(),
            'reading_value' => $reading->reading_value, ...$changes,
        ]), $reading);
    }

    private function readingOn(string $date): PumpCounterReading
    {
        return PumpCounterReading::whereDate('date', $date)->firstOrFail();
    }

    private function invokePrivate(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    /** Everything the readings feed, over the affected days (15-18 Oct). */
    private function totals(): array
    {
        $from = Carbon::parse('2026-10-15')->startOfDay();
        $to = Carbon::parse('2026-10-18')->endOfDay();
        $sales = Transaction::where('type', 'fuel_sale')->get();

        return [
            'liters_sold' => round((float) PumpCounterReading::sum('liters_sold'), 3),
            'sale_liters' => round((float) $sales->sum('liters'), 3),
            'sale_amount' => round((float) $sales->sum('amount'), 2),
            'allocated_liters' => round((float) FuelCostAllocation::sum('liters'), 3),
            'cost' => round((float) FuelCostAllocation::get()->sum(fn ($a) => $a->liters * $a->cost_per_liter_syp), 2),
            'tank_stock' => Tank::findOrFail($this->tankId)->expectedLiters(),
            'cash_box' => $this->invokePrivate(app(CashBoxController::class), 'summarize', $from, $to, true, $this->user->id),
            'statistics' => $this->invokePrivate(app(StatisticsController::class), 'summarize', Transaction::with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])->get()),
            'earnings' => $this->invokePrivate(app(EarningsController::class), 'fuelTypeBreakdown', $from, $to, 'SYP'),
        ];
    }

    /** Each reading's sale matches its liters, and no sale is left without its reading. */
    private function assertSalesMatchReadings(): void
    {
        $readings = PumpCounterReading::orderBy('date')->get();

        foreach ($readings as $reading) {
            $sale = $reading->transaction_id ? Transaction::find($reading->transaction_id) : null;

            if ($reading->liters_sold === null) {
                $this->assertNull($sale);

                continue;
            }

            $this->assertNotNull($sale, 'Reading of '.$reading->date->toDateString().' has no sale.');
            $this->assertSame(round((float) $reading->liters_sold, 3), round((float) $sale->liters, 3));
            $this->assertSame($reading->date->toDateString(), $sale->occurred_at->toDateString());
            $this->assertSame(round((float) $sale->liters, 3), round((float) FuelCostAllocation::where('transaction_id', $sale->id)->sum('liters'), 3));
        }

        $this->assertSame($readings->whereNotNull('transaction_id')->count(), Transaction::where('type', 'fuel_sale')->count());
    }

    private function assertRejected(callable $save, string $field, array $bounds = ['1,000', '1,500']): void
    {
        $before = [PumpCounterReading::count(), PumpCounterReading::pluck('liters_sold', 'id')->all(), Transaction::count()];

        try {
            $save();
            $this->fail('The reading was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            foreach ($bounds as $bound) {
                $this->assertStringContainsString($bound, $e->errors()[$field][0]);
            }
        }

        $this->assertSame($before, [PumpCounterReading::count(), PumpCounterReading::pluck('liters_sold', 'id')->all(), Transaction::count()]);
    }

    public function test_adding_a_forgotten_past_reading_splits_the_next_days_sale(): void
    {
        $before = $this->totals();

        $this->storeBulk('2026-10-16', 1200);

        $this->assertSame(200.0, (float) $this->readingOn('2026-10-16')->liters_sold);
        $this->assertSame(300.0, (float) $this->readingOn('2026-10-17')->liters_sold);
        $this->assertSame(300.0, (float) $this->readingOn('2026-10-18')->liters_sold);
        $this->assertSalesMatchReadings();
        // Same liters, money, cost and stock over the four days, now split over one more day.
        $this->assertEquals($before, $this->totals());
    }

    public function test_each_day_is_priced_at_its_own_days_price(): void
    {
        // The price went up on 17 Oct, before the 17 Oct reading was entered.
        $this->price(12000, '2026-10-17 00:00:00');
        $this->update($this->readingOn('2026-10-17'), []);

        $this->storeBulk('2026-10-16', 1200);

        $this->assertSame(2000000.0, (float) Transaction::find($this->readingOn('2026-10-16')->transaction_id)->amount);
        $this->assertSame(3600000.0, (float) Transaction::find($this->readingOn('2026-10-17')->transaction_id)->amount);
        $this->assertSame(2000000.0, (float) $this->invokePrivate(app(CashBoxController::class), 'summarize', Carbon::parse('2026-10-16'), Carbon::parse('2026-10-16')->endOfDay(), true, $this->user->id)['income']['SYP']);
        $this->assertSalesMatchReadings();
    }

    public function test_a_value_outside_the_readings_around_it_is_rejected(): void
    {
        $this->assertRejected(fn () => $this->storeBulk('2026-10-16', 1600), 'readings.0.reading_value');
        $this->assertRejected(fn () => $this->storeBulk('2026-10-16', 900), 'readings.0.reading_value');
    }

    public function test_editing_a_past_reading_recalculates_the_next_one(): void
    {
        $this->storeBulk('2026-10-16', 1200);
        $before = $this->totals();

        $this->update($this->readingOn('2026-10-16'), ['reading_value' => 1350]);

        $this->assertSame(350.0, (float) $this->readingOn('2026-10-16')->liters_sold);
        $this->assertSame(150.0, (float) $this->readingOn('2026-10-17')->liters_sold);
        $this->assertSame(300.0, (float) $this->readingOn('2026-10-18')->liters_sold);
        $this->assertSalesMatchReadings();
        $this->assertEquals($before, $this->totals());
    }

    public function test_an_edit_outside_the_readings_around_it_is_rejected(): void
    {
        $this->storeBulk('2026-10-16', 1200);

        $this->assertRejected(fn () => $this->update($this->readingOn('2026-10-16'), ['reading_value' => 1700]), 'reading_value');
    }

    public function test_moving_a_reading_to_another_day_recalculates_both_neighbours(): void
    {
        // The 17 Oct reading was really taken on the 16th: the day after its old place (18 Oct)
        // and the day after its new place (17 Oct is now empty, so 18 Oct again) stay right.
        $this->store('2026-10-14', 900);
        $before = $this->totals();

        $this->update($this->readingOn('2026-10-17'), ['date' => '2026-10-16']);

        $this->assertSame(500.0, (float) $this->readingOn('2026-10-16')->liters_sold);
        $this->assertSame(300.0, (float) $this->readingOn('2026-10-18')->liters_sold);
        $this->assertSalesMatchReadings();
        $this->assertSame($before['liters_sold'], $this->totals()['liters_sold']);
    }

    public function test_deleting_a_past_reading_gives_its_liters_back_to_the_next_one(): void
    {
        $this->storeBulk('2026-10-16', 1200);
        $before = $this->totals();

        app(PumpCounterReadingController::class)->destroy($this->readingOn('2026-10-16'));

        $this->assertSame(500.0, (float) $this->readingOn('2026-10-17')->liters_sold);
        $this->assertSalesMatchReadings();
        $this->assertEquals($before, $this->totals());
    }

    public function test_cost_is_drawn_in_day_order_when_stock_runs_short(): void
    {
        // Only 700 L of bought stock is left for these days. Adding 16 Oct must not use up a
        // layer that 17 Oct's (old, larger) sale was still holding and push the rest onto the
        // fallback cost; the total cost stays what it was.
        DB::table('fuel_cost_layers')->update(['initial_liters' => 700]);
        foreach (Transaction::where('type', 'fuel_sale')->get() as $sale) {
            FuelCostAllocation::where('transaction_id', $sale->id)->delete();
            app(FuelCostAllocationService::class)->allocate($sale);
        }
        $before = $this->totals();

        $this->storeBulk('2026-10-16', 1200);

        $this->assertSalesMatchReadings();
        $this->assertSame($before['cost'], $this->totals()['cost']);
    }

    public function test_a_value_for_a_pump_already_recorded_is_an_additional_reading(): void
    {
        // A tank switch on 17 Oct: the entry table never edits the saved 1,500 reading, a second
        // value is a second reading after it, and 18 Oct now counts from that one.
        $before = $this->totals();

        $this->storeBulk('2026-10-17', 1650);

        $day = PumpCounterReading::whereDate('date', '2026-10-17')->orderBy('id')->get();
        $this->assertSame([1500, 1650], $day->pluck('reading_value')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([500.0, 150.0], $day->pluck('liters_sold')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(150.0, (float) $this->readingOn('2026-10-18')->liters_sold);
        $this->assertSalesMatchReadings();
        $this->assertEquals($before, $this->totals());
    }

    public function test_the_readings_pdf_shows_only_the_tank_name(): void
    {
        $rows = null;
        $exporter = \Mockery::mock(PdfTableExporter::class);
        $exporter->shouldReceive('download')->once()->andReturnUsing(function (...$args) use (&$rows) {
            $rows = $args[4];

            return new Response('');
        });

        app(PumpCounterReadingController::class)->exportPdf(Request::create('/x', 'GET', ['from' => '2026-10-15', 'to' => '2026-10-18']), $exporter);

        // Columns: date, pump, tank, ...
        $this->assertSame(['مازوت اساسي'], array_values(array_unique(array_column($rows, 2))));
        $this->assertSame(['2026-10-18', '2026-10-17', '2026-10-15'], array_values(array_unique(array_column($rows, 0))));
    }
}
