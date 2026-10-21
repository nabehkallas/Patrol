<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\TransactionController;
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
 * A fuel sale counts on its reading's date (stored at noon of it), but shows when its reading
 * was saved: that time when saved on the same day, otherwise the date only with when it was
 * entered (and the shift, on a station with several), sorted at the end of its date.
 */
class FuelSaleShownTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $tankId;

    private int $pumpId;

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
        DB::table('fuel_pump_fuel_type')->insert(['pump_id' => $this->pumpId, 'fuel_type_id' => $fuelType]);
        DB::table('fuel_prices')->insert(['fuel_type_id' => $fuelType, 'price_per_liter' => 10000, 'currency' => 'SYP', 'set_by_id' => $id, 'effective_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_cost_layers')->insert(['fuel_type_id' => $fuelType, 'cost_per_liter_syp' => 8000, 'currency' => 'SYP', 'initial_liters' => 20000, 'effective_from' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tank_top_ups')->insert(['tank_id' => $this->tankId, 'liters' => 20000, 'date' => '2026-10-01', 'recorded_by_id' => $id, 'is_opening_balance' => true, 'created_at' => now(), 'updated_at' => now()]);

        Shift::defaultShift()->update(['name' => 'صباحية']);
        $this->enter('2026-10-19', 1000);
    }

    private function request(string $uri, array $input = [], string $method = 'POST'): Request
    {
        $request = Request::create($uri, $method, $input);
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    private function enter(string $date, int $value, float $governmental = 0): void
    {
        app(PumpCounterReadingController::class)->storeBulk($this->request('/pump-counters', [
            'date' => $date,
            'readings' => [['pump_id' => $this->pumpId, 'tank_id' => $this->tankId, 'reading_value' => $value, 'governmental_liters' => $governmental]],
        ]));
    }

    private function sale(string $date): Transaction
    {
        return Transaction::with(['pumpCounterReading.shift', 'governmentalReading.shift'])
            ->where('type', 'fuel_sale')->where('is_governmental', false)
            ->whereDate('occurred_at', $date)->firstOrFail();
    }

    /** The expense, the sale entered late and the one entered on time, in every list's order. */
    private function enterOctober(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 22:15:00'));
        $this->enter('2026-10-20', 1100);

        DB::table('transactions')->insert(['user_id' => $this->user->id, 'type' => 'expense', 'amount' => 5000, 'currency' => 'SYP', 'description' => 'كهرباء', 'occurred_at' => '2026-10-21 23:00:00', 'created_at' => now(), 'updated_at' => now()]);

        $this->travelTo(Carbon::parse('2026-10-22 02:30:00'));
        $this->enter('2026-10-21', 1200, 10);
    }

    public function test_a_sale_saved_on_its_reading_date_shows_when_it_was_saved(): void
    {
        $this->enterOctober();
        $sale = $this->sale('2026-10-20');

        $this->assertSame('2026-10-20 12:00:00', $sale->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(['at' => Carbon::parse('2026-10-20 22:15:00')->toIso8601String(), 'entered' => null], $sale->shownTime(true));
    }

    public function test_a_sale_saved_on_a_later_day_shows_its_date_and_when_it_was_entered(): void
    {
        $this->enterOctober();
        $sale = $this->sale('2026-10-21');

        // Still counts on its reading's date.
        $this->assertSame('2026-10-21 12:00:00', $sale->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame([
            'at' => '2026-10-21',
            'entered' => ['at' => Carbon::parse('2026-10-22 02:30:00')->toIso8601String(), 'shift' => null],
        ], $sale->shownTime());
    }

    public function test_the_shift_is_named_only_on_a_station_with_several(): void
    {
        $this->enterOctober();
        $this->assertFalse(Shift::severalActive());

        Shift::create(['position' => 2, 'name' => 'مسائية']);

        $this->assertTrue(Shift::severalActive());
        $this->assertSame('صباحية', $this->sale('2026-10-21')->shownTime(true)['entered']['shift']);
    }

    public function test_a_late_sale_sorts_at_the_end_of_its_date_in_sql_and_in_php(): void
    {
        $this->enterOctober();
        $all = Transaction::with(['pumpCounterReading.shift', 'governmentalReading.shift'])->get();

        $bySql = Transaction::query()->latestShown()->pluck('id')->all();
        $byPhp = $all->sortByDesc(fn (Transaction $t) => $t->shownSortKey().'#'.str_pad((string) $t->id, 10, '0', STR_PAD_LEFT))->pluck('id')->values()->all();
        $this->assertSame($byPhp, $bySql);

        $order = Transaction::query()->latestShown()->get()
            ->map(fn (Transaction $t) => $t->type->value.($t->is_governmental ? ' gov' : '').' '.$t->occurred_at->toDateString())
            ->all();
        $this->assertSame([
            'fuel_sale gov 2026-10-21',
            'fuel_sale 2026-10-21',
            'expense 2026-10-21',
            'fuel_sale 2026-10-20',
        ], $order);
    }

    public function test_cash_box_history_shows_and_sorts_the_same_way(): void
    {
        $this->enterOctober();
        $history = (new ReflectionMethod(CashBoxController::class, 'historyEntries'))
            ->invoke(app(CashBoxController::class), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), true, $this->user->id);

        $this->assertSame(
            [['2026-10-21', true], [Carbon::parse('2026-10-21 23:00:00')->toIso8601String(), false], [Carbon::parse('2026-10-20 22:15:00')->toIso8601String(), false]],
            collect($history)->map(fn (array $entry) => [$entry['date'], $entry['entered'] !== null])->all(),
        );
        $this->assertArrayNotHasKey('sort', $history[0]);
    }

    public function test_the_export_writes_the_entered_time_beside_the_date(): void
    {
        $this->enterOctober();
        $table = (new ReflectionMethod(TransactionController::class, 'exportTable'))
            ->invoke(app(TransactionController::class), $this->request('/transactions/export', ['from' => '2026-10-01', 'to' => '2026-10-31'], 'GET'));

        $this->assertSame([
            '2026-10-21 (Entered: 2026-10-22 02:30)',
            '2026-10-21 (Entered: 2026-10-22 02:30)',
            '2026-10-21 23:00',
            '2026-10-20 22:15',
        ], array_column((new ReflectionProperty(ExportTable::class, 'rows'))->getValue($table), 0));
    }
}
