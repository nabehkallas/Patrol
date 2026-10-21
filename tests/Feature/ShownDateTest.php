<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\TransactionController;
use App\Models\PumpCounterReading;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ExportTable;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lists show the date an entry counts for -- a reading's or fuel sale's is the reading date
 * chosen when entering it -- with the shift on a station with several, and no time. Newest date
 * first; within a date the last one saved first, then the higher id, so the order is stable.
 */
class ShownDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $tankId;

    private int $pumpId;

    private int $pump2Id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 09:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        Role::findOrCreate('admin', 'web');
        $this->user->assignRole('admin');

        $fuelType = DB::table('fuel_types')->insertGetId(['name' => 'بنزين', 'slug' => 'petrol', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->tankId = DB::table('tanks')->insertGetId(['fuel_type_id' => $fuelType, 'name' => 'بنزين اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $this->pumpId = DB::table('fuel_pumps')->insertGetId(['name' => 'بنزين 1', 'created_at' => now(), 'updated_at' => now()]);
        $this->pump2Id = DB::table('fuel_pumps')->insertGetId(['name' => 'بنزين 2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_pump_fuel_type')->insert([['pump_id' => $this->pumpId, 'fuel_type_id' => $fuelType], ['pump_id' => $this->pump2Id, 'fuel_type_id' => $fuelType]]);
        DB::table('fuel_prices')->insert(['fuel_type_id' => $fuelType, 'price_per_liter' => 10000, 'currency' => 'SYP', 'set_by_id' => $id, 'effective_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_cost_layers')->insert(['fuel_type_id' => $fuelType, 'cost_per_liter_syp' => 8000, 'currency' => 'SYP', 'initial_liters' => 20000, 'effective_from' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tank_top_ups')->insert(['tank_id' => $this->tankId, 'liters' => 20000, 'date' => '2026-10-01', 'recorded_by_id' => $id, 'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now()]);

        Shift::defaultShift()->update(['name' => 'الصبح']);
        $this->enter('2026-10-19', 1000);
        $this->enter('2026-10-19', 5000, pump: 2);
    }

    private function request(string $uri, array $input = [], string $method = 'POST'): Request
    {
        $request = Request::create($uri, $method, $input);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    private function enter(string $date, int $value, float $governmental = 0, int $pump = 1): void
    {
        app(PumpCounterReadingController::class)->storeBulk($this->request('/pump-counters', [
            'date' => $date,
            'readings' => [['pump_id' => $pump === 1 ? $this->pumpId : $this->pump2Id, 'tank_id' => $this->tankId, 'reading_value' => $value, 'governmental_liters' => $governmental]],
        ]));
    }

    private function sale(string $date): Transaction
    {
        return Transaction::with(['pumpCounterReading.shift', 'governmentalReading.shift'])
            ->where('type', 'fuel_sale')->where('is_governmental', false)
            ->whereDate('occurred_at', $date)->firstOrFail();
    }

    /** A sale entered on its day, an expense, and the next day's readings entered after midnight. */
    private function enterOctober(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 22:15:00'));
        $this->enter('2026-10-20', 1100);

        DB::table('transactions')->insert(['user_id' => $this->user->id, 'type' => 'expense', 'amount' => 5000, 'currency' => 'SYP', 'description' => 'كهرباء', 'occurred_at' => '2026-10-21 23:00:00', 'created_at' => '2026-10-21 23:05:00', 'updated_at' => '2026-10-21 23:05:00']);

        $this->travelTo(Carbon::parse('2026-10-22 02:30:00'));
        $this->enter('2026-10-21', 1200, 10);
    }

    public function test_a_sale_shows_its_reading_date_however_late_it_was_entered(): void
    {
        $this->enterOctober();

        $this->assertSame(['date' => '2026-10-20', 'shift' => null], $this->sale('2026-10-20')->shownDate());
        $this->assertSame(['date' => '2026-10-21', 'shift' => null], $this->sale('2026-10-21')->shownDate());
    }

    public function test_a_sale_entered_on_a_later_day_still_counts_on_its_reading_date(): void
    {
        $this->enterOctober();
        $this->assertSame('2026-10-21 12:00:00', $this->sale('2026-10-21')->occurred_at->format('Y-m-d H:i:s'));

        $summarize = new ReflectionMethod(CashBoxController::class, 'summarize');
        $day = fn (string $date) => $summarize->invoke(app(CashBoxController::class), Carbon::parse($date)->startOfDay(), Carbon::parse($date)->endOfDay(), true, $this->user->id);

        $this->assertEquals(100, $day('2026-10-21')['liters_sold']);
        $this->assertEquals(0, $day('2026-10-22')['liters_sold']);
    }

    public function test_the_shift_is_named_only_on_a_station_with_several(): void
    {
        $this->enterOctober();
        $this->assertFalse(Shift::severalActive());

        Shift::create(['position' => 2, 'name' => 'المساء']);

        $this->assertTrue(Shift::severalActive());
        $this->assertSame(['date' => '2026-10-21', 'shift' => 'الصبح'], $this->sale('2026-10-21')->shownDate(true));
    }

    public function test_transactions_sort_by_date_then_last_saved_in_sql_and_in_php(): void
    {
        $this->enterOctober();
        $all = Transaction::with(['pumpCounterReading', 'governmentalReading'])->get();

        $bySql = Transaction::query()->latestShown()->pluck('id')->all();
        $byPhp = $all->sortByDesc(fn (Transaction $t) => $t->shownSortKey().'#'.str_pad((string) $t->id, 10, '0', STR_PAD_LEFT))->pluck('id')->values()->all();
        $this->assertSame($byPhp, $bySql);

        // 21 Oct: the sale saved at 02:30 the next night is the last saved, so it is on top.
        $this->assertSame([
            'fuel_sale gov 2026-10-21',
            'fuel_sale 2026-10-21',
            'expense 2026-10-21',
            'fuel_sale 2026-10-20',
        ], Transaction::query()->latestShown()->get()
            ->map(fn (Transaction $t) => $t->type->value.($t->is_governmental ? ' gov' : '').' '.$t->occurred_at->toDateString())
            ->all());
    }

    public function test_readings_list_the_last_saved_first_within_a_date_and_never_reorder(): void
    {
        // Pump 1, then pump 2 for the same date: pump 2 is on top.
        $this->travelTo(Carbon::parse('2026-10-21 01:10:00'));
        $this->enter('2026-10-20', 1100);
        $this->travelTo(Carbon::parse('2026-10-21 01:12:00'));
        $this->enter('2026-10-20', 5100, pump: 2);
        // Both pumps again in the same second: the later one (higher id) first, every time.
        $this->travelTo(Carbon::parse('2026-10-21 23:00:00'));
        $this->enter('2026-10-21', 1200);
        $this->enter('2026-10-21', 5200, pump: 2);

        $order = fn () => PumpCounterReading::query()->latestShown()->get()
            ->map(fn (PumpCounterReading $r) => $r->date->toDateString().' '.$r->reading_value)->all();

        $expected = ['2026-10-21 5200', '2026-10-21 1200', '2026-10-20 5100', '2026-10-20 1100', '2026-10-19 5000', '2026-10-19 1000'];
        $this->assertSame($expected, $order());
        $this->assertSame($expected, $order());
    }

    public function test_cash_box_history_shows_and_sorts_the_same_way(): void
    {
        $this->enterOctober();
        $history = (new ReflectionMethod(CashBoxController::class, 'historyEntries'))
            ->invoke(app(CashBoxController::class), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), true, $this->user->id);

        $this->assertSame(
            [['2026-10-21', 'fuel_sale'], ['2026-10-21', 'expense'], ['2026-10-20', 'fuel_sale']],
            collect($history)->map(fn (array $entry) => [$entry['date'], Transaction::find((int) substr($entry['id'], 12))->type->value])->all(),
        );
        $this->assertArrayNotHasKey('sort', $history[0]);
    }

    public function test_the_export_writes_the_date_and_no_one_who_recorded_it(): void
    {
        $this->enterOctober();
        $table = (new ReflectionMethod(TransactionController::class, 'exportTable'))
            ->invoke(app(TransactionController::class), $this->request('/transactions/export', ['from' => '2026-10-01', 'to' => '2026-10-31'], 'GET'));
        $rows = (new ReflectionProperty(ExportTable::class, 'rows'))->getValue($table);

        $this->assertSame(['2026-10-21', '2026-10-21', '2026-10-21', '2026-10-20'], array_column($rows, 0));
        $this->assertCount(6, (new ReflectionProperty(ExportTable::class, 'headers'))->getValue($table));
    }
}
