<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\TransactionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Who can correct a recorded transaction, and which screen owns it: fuel sales are recorded by
 * pump counters only, and shop and Sadcop entries are corrected on their own screens.
 */
class TransactionRulesTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin, int $id = 7): User
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isAdmin')->andReturn($admin);
        $user->id = $id;

        return $user;
    }

    public function test_only_admins_change_or_delete_a_recorded_transaction(): void
    {
        $policy = new TransactionPolicy;
        $transaction = new Transaction(['user_id' => 7]);

        $this->assertTrue($policy->create($this->user(admin: false)));
        $this->assertFalse($policy->update($this->user(admin: false), $transaction), 'Not even one they recorded');
        $this->assertFalse($policy->delete($this->user(admin: false), $transaction));
        $this->assertTrue($policy->update($this->user(admin: true, id: 1), $transaction));
        $this->assertTrue($policy->delete($this->user(admin: true, id: 1), $transaction));
    }

    public function test_each_transaction_knows_the_screen_that_owns_it(): void
    {
        $this->assertSame('pump_counters', (new Transaction(['type' => TransactionType::FuelSale]))->managedBy());
        $this->assertSame('shop', (new Transaction(['type' => TransactionType::OtherIncome, 'shop_item_id' => 3]))->managedBy());

        $expense = new Transaction(['type' => TransactionType::Expense]);
        $expense->setRelation('sadcopLedgerEntry', null);
        $this->assertNull($expense->managedBy());
    }

    public function test_fuel_sales_cannot_be_entered_by_hand(): void
    {
        $rules = (new StoreTransactionRequest)->rules();

        $this->assertTrue(Validator::make(['type' => 'fuel_sale'], ['type' => $rules['type']])->fails());
        $this->assertFalse(Validator::make(['type' => 'expense'], ['type' => $rules['type']])->fails());
    }

    public function test_a_missing_page_gets_the_translated_error_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('error')->where('status', 404));
    }
}
