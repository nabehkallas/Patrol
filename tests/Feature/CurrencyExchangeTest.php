<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The currency exchange is the one operation that uses an exchange rate: it needs one, stores
 * the one entered on its own record, and moves the two amounts between the currencies' boxes.
 * Nothing else stores a rate.
 */
class CurrencyExchangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        DB::table('exchange_rates')->insert(['currency' => 'SYP', 'rate_to_usd' => 138, 'set_by_id' => $id, 'effective_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Saves a transaction through the real controller and returns the stored row. */
    private function store(array $input): Transaction
    {
        $request = StoreTransactionRequest::create('/transactions', 'POST', $input);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $this->user);
        $request->validateResolved();

        app(TransactionController::class)->store($request);

        return Transaction::query()->latest('id')->firstOrFail();
    }

    public function test_an_exchange_needs_a_rate(): void
    {
        $rules = (new StoreTransactionRequest)->rules();
        $exchange = ['type' => 'currency_exchange', 'amount' => 15000000, 'currency' => 'SYP', 'to_amount' => 111111.11, 'to_currency' => 'USD'];

        $this->assertTrue(Validator::make($exchange, ['exchange_rate_to_usd' => $rules['exchange_rate_to_usd']])->fails());
        $this->assertFalse(Validator::make([...$exchange, 'exchange_rate_to_usd' => 135], ['exchange_rate_to_usd' => $rules['exchange_rate_to_usd']])->fails());
    }

    public function test_the_exchange_stores_the_rate_entered_and_its_two_amounts(): void
    {
        // Today's saved rate is 138, but this exchange was made at 135.
        $exchange = $this->store([
            'type' => 'currency_exchange', 'amount' => 15000000, 'currency' => 'SYP',
            'to_amount' => 111111.11, 'to_currency' => 'USD', 'exchange_rate_to_usd' => 135,
            'occurred_at' => '2026-10-11',
        ]);

        $this->assertSame(135.0, (float) $exchange->exchange_rate_to_usd);
        $this->assertSame(15000000.0, (float) $exchange->amount);
        $this->assertSame('SYP', $exchange->currency);
        $this->assertSame(111111.11, (float) $exchange->to_amount);
        $this->assertSame('USD', $exchange->to_currency);
    }

    public function test_nothing_but_an_exchange_stores_a_rate(): void
    {
        $expense = $this->store([
            'type' => 'expense', 'amount' => 75000, 'currency' => 'SYP', 'description' => 'Repairs',
            'exchange_rate_to_usd' => 138, 'occurred_at' => '2026-10-11',
        ]);

        $this->assertNull($expense->exchange_rate_to_usd);
        $this->assertSame(75000.0, (float) $expense->amount);
    }
}
