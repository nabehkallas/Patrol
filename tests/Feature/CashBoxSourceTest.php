<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\CashBoxOpeningController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\DebtorController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\TransactionController;
use App\Http\Requests\StoreDebtPaymentRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\CashBoxOpening;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\DebtPayment;
use App\Models\ShopItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBox;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Money going out of the cash box, one box per currency. Another currency is paid from its own
 * box (only if it holds enough) or from the SYP box at a rate -- then the record is in SYP, with
 * the original amount and rate kept as information. The SYP box may go negative, once confirmed.
 */
class CashBoxSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        Role::findOrCreate('admin', 'web');
        $this->user->assignRole('admin');
        $this->actingAs($this->user);
        DB::table('exchange_rates')->insert(['currency' => 'SYP', 'rate_to_usd' => 139, 'set_by_id' => $id, 'effective_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  class-string<FormRequest>|null  $form */
    private function request(array $input, ?string $form = null, string $method = 'POST'): Request
    {
        $request = ($form ?? Request::class)::create('/x', $method, $input);
        $request->setUserResolver(fn () => $this->user);

        if ($request instanceof FormRequest) {
            $request->setContainer(app())->setRedirector(app('redirect'));
            $request->validateResolved();
        }

        return $request;
    }

    private function income(float $amount, string $currency = 'SYP'): void
    {
        Transaction::create(['user_id' => $this->user->id, 'type' => 'other_income', 'other_income_category' => 'cash_box', 'description' => 'Sales', 'amount' => $amount, 'currency' => $currency, 'occurred_at' => now()->subHour()]);
    }

    private function expense(array $input): void
    {
        app(TransactionController::class)->store($this->request(['type' => 'expense', 'description' => 'Repairs', 'occurred_at' => '2026-10-21', ...$input], StoreTransactionRequest::class));
    }

    /** @return array<string, float> */
    private function balances(): array
    {
        return app(CashBox::class)->balances();
    }

    private function rejected(callable $save): array
    {
        try {
            $save();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('It was saved.');
    }

    public function test_a_usd_payment_is_blocked_when_the_usd_box_lacks_it(): void
    {
        $this->income(100000);

        $errors = $this->rejected(fn () => $this->expense(['amount' => 50, 'currency' => 'USD']));

        $this->assertSame(['Not enough in the US Dollar box (available: 0.00 USD). You can pay from the Syrian Pound box at an exchange rate.'], $errors['amount']);
        $this->assertSame(1, Transaction::count());
    }

    public function test_paid_from_the_syp_box_it_is_recorded_in_syp_with_the_original_amount(): void
    {
        $this->income(100000);

        $this->expense(['amount' => 50, 'currency' => 'USD', 'pay_from' => 'primary', 'cash_rate' => 139]);

        $expense = Transaction::where('type', 'expense')->sole();
        $this->assertSame(['SYP', 6950.0], [$expense->currency, (float) $expense->amount]);
        $this->assertSame(['amount' => 50.0, 'currency' => 'USD', 'rate' => 139.0], $expense->originalPayment());
        $this->assertSame('50.00 USD @ 139', $expense->originalNote());
        // 6,950 left the SYP box; the USD box never moved.
        $this->assertSame(['SYP' => 93050.0], $this->balances());

        $history = collect((new \ReflectionMethod(CashBoxController::class, 'historyEntries'))
            ->invoke(app(CashBoxController::class), now()->startOfDay(), now()->endOfDay(), true, $this->user->id));
        $row = $history->firstWhere('type', 'expense');
        $this->assertSame([6950.0, 'SYP', ['amount' => 50.0, 'currency' => 'USD', 'rate' => 139.0]], [$row['amount'], $row['currency'], $row['original']]);
    }

    public function test_the_usd_box_pays_when_it_holds_enough(): void
    {
        $this->income(100, 'USD');

        $this->expense(['amount' => 60, 'currency' => 'USD', 'pay_from' => 'own']);
        $errors = $this->rejected(fn () => $this->expense(['amount' => 50, 'currency' => 'USD', 'pay_from' => 'own']));

        $this->assertStringStartsWith('Not enough in the US Dollar box (available: 40.00 USD)', $errors['amount'][0]);
        $this->assertSame(['SYP' => 0.0, 'USD' => 40.0], $this->balances());
    }

    public function test_the_syp_box_going_negative_asks_first(): void
    {
        $this->income(5000);

        $errors = $this->rejected(fn () => $this->expense(['amount' => 8000, 'currency' => 'SYP']));
        $this->assertSame(['The Syrian Pound box will go negative (available: 5,000 SYP). Continue?'], $errors[CashBox::CONFIRM_FIELD]);
        $this->assertSame(0, Transaction::where('type', 'expense')->count());

        $this->expense(['amount' => 8000, 'currency' => 'SYP', CashBox::CONFIRM_FIELD => true]);
        $this->assertSame(['SYP' => -3000.0], $this->balances());
    }

    public function test_an_edited_record_is_checked_without_its_own_old_amount(): void
    {
        $this->income(10000);
        $this->expense(['amount' => 8000, 'currency' => 'SYP']);
        $expense = Transaction::where('type', 'expense')->sole();

        // 9,000 fits once its own 8,000 is back in the box (10,000 available, not 2,000).
        app(TransactionController::class)->update($this->request(['type' => 'expense', 'description' => 'Repairs', 'amount' => 9000, 'currency' => 'SYP', 'occurred_at' => '2026-10-21'], UpdateTransactionRequest::class, 'PUT'), $expense);

        $this->assertSame(9000.0, (float) $expense->fresh()->amount);
        $this->assertSame(['SYP' => 1000.0], $this->balances());
    }

    public function test_a_recorded_debt_takes_nothing_out_until_paid(): void
    {
        $debtor = Debtor::create(['name' => 'المورد']);

        $this->expense(['amount' => 50, 'currency' => 'USD', 'mark_as_debt' => true, 'debt_debtor_id' => $debtor->id, 'debt_direction' => 'payable']);

        $this->assertSame(1, Transaction::where('type', 'expense')->count());
    }

    public function test_a_store_purchase_in_usd_can_be_paid_from_the_syp_box(): void
    {
        $this->income(100000);
        $item = ShopItem::create(['name' => 'زيت']);
        $item->prices()->create(['base_price' => 750, 'sell_price' => 1000, 'currency' => 'SYP', 'set_by_id' => $this->user->id, 'effective_at' => now()->subDay()]);
        $buy = fn (array $input) => app(ShopController::class)->storePurchase($this->request(['shop_item_id' => $item->id, 'quantity' => 2, 'amount' => 50, 'currency' => 'USD', 'date' => '2026-10-21', ...$input]));

        $this->assertArrayHasKey('amount', $this->rejected(fn () => $buy([])));
        $buy(['pay_from' => 'primary', 'cash_rate' => 139]);

        $purchase = Transaction::where('type', 'purchase')->sole();
        $this->assertSame(['SYP', 6950.0, 2], [$purchase->currency, (float) $purchase->amount, $purchase->quantity]);
        $this->assertSame('50.00 USD @ 139', $purchase->originalNote());
        $this->assertSame(2, $item->currentStock());
    }

    public function test_a_usd_debt_paid_from_the_syp_box_is_paid_in_usd_and_takes_syp(): void
    {
        $this->income(100000);
        $debt = Debt::create(['direction' => 'payable', 'debtor_id' => Debtor::create(['name' => 'المورد'])->id, 'amount' => 50, 'currency' => 'USD', 'date' => '2026-10-20', 'status' => 'outstanding', 'recorded_by_id' => $this->user->id]);

        $this->assertArrayHasKey('amount', $this->rejected(fn () => app(DebtController::class)->storePayment($this->request(['amount' => 50], StoreDebtPaymentRequest::class), $debt)));

        app(DebtController::class)->storePayment($this->request(['amount' => 50, 'pay_from' => 'primary', 'cash_rate' => 139], StoreDebtPaymentRequest::class), $debt);

        $payment = DebtPayment::sole();
        $this->assertSame([50.0, 6950.0, 'SYP', 139.0], [(float) $payment->amount, $payment->cashAmount(), $payment->cashCurrency(), (float) $payment->cash_rate]);
        $this->assertSame('settled', $debt->fresh()->status->value);
        $this->assertSame(['SYP' => 93050.0], $this->balances());
    }

    public function test_settling_several_debts_at_once_checks_each_box(): void
    {
        $debtor = Debtor::create(['name' => 'المورد']);
        Debt::create(['direction' => 'payable', 'debtor_id' => $debtor->id, 'amount' => 20, 'currency' => 'USD', 'date' => '2026-10-20', 'status' => 'outstanding', 'recorded_by_id' => $this->user->id]);

        $errors = $this->rejected(fn () => app(DebtorController::class)->settleAll($this->request([]), $debtor));

        $this->assertSame(['Not enough in the US Dollar box (available: 0.00 USD).'], $errors['amount']);
        $this->assertSame(0, DebtPayment::count());
    }

    public function test_opening_cash_counts_in_the_cash_box_only(): void
    {
        $save = fn (array $input) => app(CashBoxOpeningController::class)->store($this->request(['date' => '2026-10-01', ...$input]));
        $save(['currency' => 'USD', 'amount' => 300]);
        $save(['currency' => 'USD', 'amount' => 250]);
        $save(['currency' => 'SYP', 'amount' => 1000000]);

        // One per box: saving again replaced it.
        $this->assertSame(2, CashBoxOpening::count());
        $this->assertSame(['SYP' => 1000000.0, 'USD' => 250.0], $this->balances());
        $this->expense(['amount' => 200, 'currency' => 'USD', 'pay_from' => 'own']);
        $this->assertSame(['SYP' => 1000000.0, 'USD' => 50.0], $this->balances());

        // On its date it shows as money in; it isn't income anywhere else.
        $summary = app(CashBoxController::class)->summarize(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-01')->endOfDay(), true, $this->user->id);
        $this->assertSame(['SYP' => 1000000.0, 'USD' => 250.0], $summary['opening_cash']);
        $this->assertSame(['SYP' => 0.0], $summary['income']);
        $this->assertSame(0, Transaction::where('type', 'other_income')->count());
    }
}
