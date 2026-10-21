<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\StatisticsController;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\ShopItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnnualFinancialSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The cash-source migration on a station that already has data: it only adds empty columns
 * and an empty table. No stored value changes, and no figure on any report: every record
 * stays in its own currency's box, and a payment in the debt's.
 */
class CashSourceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_22_000001_add_cash_source_columns';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);

        // A station's money as it is today: sales and expenses in SYP and USD, a Store purchase,
        // an exchange, debts both ways with payments (one partial), and a cash advance.
        $tx = fn (array $row) => Transaction::create(['user_id' => $id, 'occurred_at' => '2026-10-15 10:00:00', ...$row]);
        $tx(['type' => 'other_income', 'other_income_category' => 'cash_box', 'description' => 'Sales', 'amount' => 2500000, 'currency' => 'SYP']);
        $tx(['type' => 'other_income', 'other_income_category' => 'government', 'description' => 'Smart card', 'amount' => 400000, 'currency' => 'SYP']);
        $tx(['type' => 'other_income', 'description' => 'Rent', 'amount' => 300, 'currency' => 'USD', 'other_income_category' => 'other']);
        $tx(['type' => 'expense', 'description' => 'Repairs', 'amount' => 75000, 'currency' => 'SYP', 'occurred_at' => '2026-10-16 09:00:00']);
        $tx(['type' => 'expense', 'description' => 'Parts', 'amount' => 100, 'currency' => 'USD', 'occurred_at' => '2026-10-17 09:00:00']);
        $tx(['type' => 'currency_exchange', 'amount' => 1390000, 'currency' => 'SYP', 'to_amount' => 100, 'to_currency' => 'USD', 'exchange_rate_to_usd' => 13900, 'occurred_at' => '2026-10-18 09:00:00']);
        $item = ShopItem::create(['name' => 'زيت']);
        $item->prices()->create(['base_price' => 750, 'sell_price' => 1000, 'currency' => 'SYP', 'set_by_id' => $id, 'effective_at' => '2026-10-01']);
        $tx(['type' => 'purchase', 'shop_item_id' => $item->id, 'quantity' => 10, 'description' => 'زيت × 10', 'amount' => 7500, 'currency' => 'SYP']);
        $tx(['type' => 'other_income', 'shop_item_id' => $item->id, 'quantity' => 4, 'description' => 'زيت × 4', 'amount' => 4000, 'currency' => 'SYP', 'occurred_at' => '2026-10-19 09:00:00']);

        $supplier = Debtor::create(['name' => 'المورد']);
        $customer = Debtor::create(['name' => 'زبون']);
        $debt = fn (array $row) => Debt::create(['date' => '2026-10-15', 'status' => 'outstanding', 'recorded_by_id' => $id, ...$row]);
        $debt(['direction' => 'payable', 'debtor_id' => $supplier->id, 'amount' => 50, 'currency' => 'USD'])->recordPayment(20, $id, null, Carbon::parse('2026-10-18 11:00:00'));
        $debt(['direction' => 'payable', 'debtor_id' => $supplier->id, 'amount' => 200000, 'currency' => 'SYP'])->recordPayment(200000, $id, null, Carbon::parse('2026-10-19 11:00:00'));
        $debt(['direction' => 'receivable', 'debtor_id' => $customer->id, 'amount' => 90000, 'currency' => 'SYP'])->recordPayment(90000, $id, null, Carbon::parse('2026-10-20 11:00:00'));
        $tx(['type' => 'expense', 'description' => 'زبون — cash advance', 'amount' => 30000, 'currency' => 'SYP', 'occurred_at' => '2026-10-20 12:00:00']);
    }

    private function invokePrivate(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    /** Every stored row, without the new columns. */
    private function rows(): array
    {
        $new = ['original_amount', 'original_currency', 'original_rate', 'cash_amount', 'cash_currency', 'cash_rate'];
        $table = fn (string $name) => DB::table($name)->orderBy('id')->get()
            ->map(fn ($row) => collect((array) $row)->except($new)->all())->all();

        return ['transactions' => $table('transactions'), 'debts' => $table('debts'), 'payments' => $table('debt_payments')];
    }

    /** Every report figure, without what the new code adds (opening cash, a row's original amount). */
    private function figures(): array
    {
        $from = Carbon::parse('2026-10-01')->startOfDay();
        $to = now()->endOfDay();
        $cashBox = app(CashBoxController::class);
        $transactions = Transaction::with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])->get();

        return [
            'cash_box' => collect($cashBox->summarize($from, $to, true, $this->user->id))->except('opening_cash')->all(),
            'cash_box_by_day' => collect(range(15, 21))->map(fn (int $day) => $cashBox->summarize(Carbon::parse("2026-10-{$day}"), Carbon::parse("2026-10-{$day}")->endOfDay(), true, $this->user->id)['net'])->all(),
            'cash_box_history' => collect($this->invokePrivate($cashBox, 'historyEntries', $from, $to, true, $this->user->id))->map(fn (array $row) => collect($row)->except('original')->all())->all(),
            'statistics' => $this->invokePrivate(app(StatisticsController::class), 'summarize', $transactions),
            'earnings_shop' => $this->invokePrivate(app(EarningsController::class), 'shopProfitSummary', $from, $to, 'SYP'),
            'earnings_other_expenses' => [$this->invokePrivate(app(EarningsController::class), 'otherExpenses', $from, $to, 'SYP'), $this->invokePrivate(app(EarningsController::class), 'otherExpenses', $from, $to, 'USD')],
            'annual' => app(AnnualFinancialSummary::class)->forYear(2026),
        ];
    }

    public function test_the_migration_changes_no_stored_value_or_figure(): void
    {
        $rowsBefore = $this->rows();
        $figuresBefore = $this->figures();
        $migration = require database_path('migrations/tenant/'.self::MIGRATION.'.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('transactions', 'original_amount'));
        $this->assertFalse(Schema::hasColumn('debt_payments', 'cash_amount'));
        $this->assertFalse(Schema::hasTable('cash_box_openings'));
        $this->assertEquals($rowsBefore, $this->rows());

        $migration->up();
        $this->assertEquals($rowsBefore, $this->rows());
        $this->assertEquals($figuresBefore, $this->figures());
        // Every record in its own currency; nothing converted.
        $this->assertSame(0, Transaction::whereNotNull('original_currency')->count());
        $this->assertSame(0, DB::table('debt_payments')->whereNotNull('cash_currency')->count());
        // 2,994,000 in - 312,500 out - 1,390,000 exchanged; 300 + 100 exchanged - 100 - 20 paid.
        $this->assertSame(['SYP' => 1291500.0, 'USD' => 280.0], $figuresBefore['cash_box']['net']);
    }
}
