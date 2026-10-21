<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\TransactionController;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Only a fuel delivery is about a tank: an expense entered on the Transactions page (whose form
 * always sends the selected tank) is saved without one, and is shown by its description --
 * including older expenses that were saved with the tank's fuel type.
 */
class TransactionFuelFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $tankId;

    private int $fuelTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        $this->fuelTypeId = DB::table('fuel_types')->insertGetId(['name' => 'بنزين', 'slug' => 'petrol', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->tankId = DB::table('tanks')->insertGetId(['fuel_type_id' => $this->fuelTypeId, 'name' => 'بنزين اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function store(array $input): Transaction
    {
        $request = StoreTransactionRequest::create('/transactions', 'POST', [...$input, 'confirm_negative_cash' => true]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $this->user);
        $request->validateResolved();
        app(TransactionController::class)->store($request);

        return Transaction::latest('id')->firstOrFail();
    }

    private function historyDescriptions(): array
    {
        return collect((new ReflectionMethod(CashBoxController::class, 'historyEntries'))
            ->invoke(app(CashBoxController::class), now()->startOfDay(), now()->endOfDay(), true, $this->user->id))
            ->pluck('description', 'id')->all();
    }

    public function test_an_expense_is_saved_without_the_tank_the_form_sends(): void
    {
        $expense = $this->store(['type' => 'expense', 'tank_id' => $this->tankId, 'liters' => '', 'price_per_liter' => '1500', 'description' => 'صيانة', 'amount' => 5000, 'currency' => 'SYP', 'occurred_at' => '2026-10-21']);

        $this->assertNull($expense->tank_id);
        $this->assertNull($expense->fuel_type_id);
        $this->assertNull($expense->price_per_liter);
        $this->assertSame(5000.0, (float) $expense->amount);
    }

    public function test_a_fuel_delivery_keeps_its_tank_and_fuel_type(): void
    {
        $delivery = $this->store(['type' => 'fuel_delivery', 'tank_id' => $this->tankId, 'liters' => 1000, 'price_per_liter' => 1500, 'amount' => 1500000, 'currency' => 'SYP', 'occurred_at' => '2026-10-21']);

        $this->assertSame([$this->tankId, $this->fuelTypeId], [$delivery->tank_id, $delivery->fuel_type_id]);
    }

    public function test_older_expenses_with_a_fuel_type_show_their_description(): void
    {
        // As saved before the fix: the first tank's fuel type on an expense and an income record.
        $old = fn (array $row) => Transaction::create(['user_id' => $this->user->id, 'fuel_type_id' => $this->fuelTypeId, 'tank_id' => $this->tankId, 'currency' => 'SYP', 'occurred_at' => now(), ...$row]);
        $expense = $old(['type' => 'expense', 'description' => 'خبز و قهوة', 'amount' => 20000]);
        $income = $old(['type' => 'other_income', 'other_income_category' => 'cash_box', 'description' => 'الصندوق', 'amount' => 90000]);
        $blank = $old(['type' => 'expense', 'description' => null, 'amount' => 1000]);

        $history = $this->historyDescriptions();

        $this->assertSame('خبز و قهوة', $history['transaction-'.$expense->id]);
        $this->assertSame('الصندوق', $history['transaction-'.$income->id]);
        $this->assertSame('Expense', $history['transaction-'.$blank->id]);
        // Amounts untouched.
        $this->assertSame([20000.0, 90000.0, 1000.0], Transaction::orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
    }
}
