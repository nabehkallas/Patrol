<?php

namespace Tests\Feature;

use App\Http\Controllers\PumpCounterReadingController;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\ShopItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lists show no time and no name: the audit log is where an entry's exact save time and who
 * saved it are. Every reading, fuel sale, expense, income, store entry, debt (and payment) and
 * exchange is logged with who, when, the date it is entered for -- on an edit too, even when
 * that date didn't change -- and what changed.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 09:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Samir', 'email' => 's@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        Role::findOrCreate('admin', 'web');
        $this->user->assignRole('admin');
        $this->actingAs($this->user);

        // AuditLog::record() only writes inside a station.
        tenancy()->initialized = true;
    }

    protected function tearDown(): void
    {
        tenancy()->initialized = false;

        parent::tearDown();
    }

    /** @return array{fuelType: int, tank: int, pump: int} */
    private function station(): array
    {
        $fuelType = DB::table('fuel_types')->insertGetId(['name' => 'بنزين', 'slug' => 'petrol', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $tank = DB::table('tanks')->insertGetId(['fuel_type_id' => $fuelType, 'name' => 'بنزين اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        $pump = DB::table('fuel_pumps')->insertGetId(['name' => 'بنزين 1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_pump_fuel_type')->insert(['pump_id' => $pump, 'fuel_type_id' => $fuelType]);
        DB::table('fuel_prices')->insert(['fuel_type_id' => $fuelType, 'price_per_liter' => 10000, 'currency' => 'SYP', 'set_by_id' => $this->user->id, 'effective_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_cost_layers')->insert(['fuel_type_id' => $fuelType, 'cost_per_liter_syp' => 8000, 'currency' => 'SYP', 'initial_liters' => 20000, 'effective_from' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);

        return ['fuelType' => $fuelType, 'tank' => $tank, 'pump' => $pump];
    }

    private function enter(array $station, string $date, int $value, float $governmental = 0): void
    {
        $request = Request::create('/pump-counters', 'POST', [
            'date' => $date,
            'readings' => [['pump_id' => $station['pump'], 'tank_id' => $station['tank'], 'reading_value' => $value, 'governmental_liters' => $governmental]],
        ]);
        $request->setUserResolver(fn () => $this->user);
        app(PumpCounterReadingController::class)->storeBulk($request);
    }

    private function log(string $entity, string $action): AuditLog
    {
        return AuditLog::where('entity_type', $entity)->where('action', $action)->latest('id')->firstOrFail();
    }

    private function assertWhoAndWhen(AuditLog $log, string $savedAt): void
    {
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame('Samir', $log->user_name);
        $this->assertSame($savedAt, $log->created_at->format('Y-m-d H:i:s'));
    }

    public function test_a_reading_entered_late_logs_who_when_and_the_date_it_is_for_with_its_sale_and_debt(): void
    {
        $station = $this->station();
        $this->enter($station, '2026-10-19', 1000);

        $this->travelTo(Carbon::parse('2026-10-21 02:19:00'));
        $this->enter($station, '2026-10-20', 1100, 10);

        $reading = $this->log('PumpCounterReading', 'created');
        $this->assertWhoAndWhen($reading, '2026-10-21 02:19:00');
        $this->assertSame('2026-10-20 00:00:00', $reading->new_values['date']);
        $this->assertArrayHasKey('shift_id', $reading->new_values);

        $sale = $this->log('Transaction', 'created');
        $this->assertWhoAndWhen($sale, '2026-10-21 02:19:00');
        $this->assertSame('2026-10-20', substr($sale->new_values['occurred_at'], 0, 10));

        $debt = $this->log('Debt', 'created');
        $this->assertWhoAndWhen($debt, '2026-10-21 02:19:00');
        $this->assertSame('2026-10-20', substr($debt->new_values['date'], 0, 10));
    }

    public function test_an_edit_names_the_date_even_when_only_the_amount_changed_and_a_delete_keeps_everything(): void
    {
        $expense = Transaction::create(['user_id' => $this->user->id, 'type' => 'expense', 'amount' => 5000, 'currency' => 'SYP', 'description' => 'كهرباء', 'occurred_at' => '2026-10-15 10:00:00']);

        $this->travelTo(Carbon::parse('2026-10-21 08:30:00'));
        $expense->update(['amount' => 6000]);

        $edit = $this->log('Transaction', 'updated');
        $this->assertWhoAndWhen($edit, '2026-10-21 08:30:00');
        $this->assertEquals(['occurred_at' => '2026-10-15 10:00:00', 'type' => 'expense', 'amount' => '5000.00'], $edit->old_values);
        $this->assertEquals(['occurred_at' => '2026-10-15 10:00:00', 'type' => 'expense', 'amount' => 6000], $edit->new_values);

        $this->travelTo(Carbon::parse('2026-10-21 08:45:00'));
        $expense->delete();

        $delete = $this->log('Transaction', 'deleted');
        $this->assertWhoAndWhen($delete, '2026-10-21 08:45:00');
        $this->assertSame('2026-10-15 10:00:00', $delete->old_values['occurred_at']);
        $this->assertEquals('6000.00', $delete->old_values['amount']);
    }

    public function test_income_store_exchange_and_debt_payments_are_logged_with_their_dates(): void
    {
        $item = ShopItem::create(['name' => 'زيت']);
        Transaction::create(['user_id' => $this->user->id, 'type' => 'other_income', 'amount' => 900, 'currency' => 'SYP', 'occurred_at' => '2026-10-18 09:00:00']);
        Transaction::create(['user_id' => $this->user->id, 'type' => 'other_income', 'shop_item_id' => $item->id, 'quantity' => 2, 'amount' => 800, 'currency' => 'SYP', 'occurred_at' => '2026-10-18 09:05:00']);
        Transaction::create(['user_id' => $this->user->id, 'type' => 'currency_exchange', 'amount' => 139000, 'currency' => 'SYP', 'to_amount' => 10, 'to_currency' => 'USD', 'occurred_at' => '2026-10-18 09:10:00']);

        $created = AuditLog::where('entity_type', 'Transaction')->where('action', 'created')->orderBy('id')->get();
        $this->assertSame(['other_income', 'other_income', 'currency_exchange'], $created->map(fn (AuditLog $log) => $log->new_values['type'])->all());
        $this->assertSame($item->id, $created[1]->new_values['shop_item_id']);
        foreach ($created as $log) {
            $this->assertWhoAndWhen($log, '2026-10-19 09:00:00');
            $this->assertSame('2026-10-18', substr($log->new_values['occurred_at'], 0, 10));
        }

        $debt = Debt::create(['debtor_id' => Debtor::create(['name' => 'أبو أحمد'])->id, 'amount' => 5000, 'currency' => 'SYP', 'date' => '2026-10-10', 'direction' => 'receivable', 'status' => 'outstanding', 'recorded_by_id' => $this->user->id]);
        $payment = $debt->recordPayment(2000, $this->user->id, null, Carbon::parse('2026-10-17 12:00:00'));

        $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
        $payment->update(['amount' => 2500]);

        $edit = $this->log('DebtPayment', 'updated');
        $this->assertWhoAndWhen($edit, '2026-10-21 09:00:00');
        $this->assertSame('2026-10-17', substr($edit->new_values['paid_at'], 0, 10));
        $this->assertSame($edit->old_values['paid_at'], $edit->new_values['paid_at']);
        $this->assertSame($debt->id, $edit->new_values['debt_id']);
    }
}
