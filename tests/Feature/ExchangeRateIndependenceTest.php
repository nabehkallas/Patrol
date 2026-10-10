<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\StatisticsController;
use App\Models\SadcopLedgerEntry;
use App\Models\Transaction;
use App\Services\AnnualFinancialSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every amount stays in its own currency; the exchange rate is used only by a currency exchange.
 * So changing the rate (or setting one for the first time) must not move any figure on the
 * Cash Box, Sadcop, Statistics, Earnings or the annual summary.
 */
class ExchangeRateIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 12:00:00'));
        $this->userId = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $petrol = DB::table('fuel_types')->insertGetId(['name' => 'Petrol', 'slug' => 'petrol', 'profit_margin_percent' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $tank = DB::table('tanks')->insertGetId(['fuel_type_id' => $petrol, 'name' => 'T1', 'capacity_liters' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_prices')->insert(['fuel_type_id' => $petrol, 'price_per_liter' => 15000, 'currency' => 'SYP', 'set_by_id' => $this->userId, 'effective_at' => '2026-09-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);

        $tx = fn (array $values) => DB::table('transactions')->insertGetId([
            'user_id' => $this->userId, 'created_at' => now(), 'updated_at' => now(), ...$values,
        ]);

        // A mix of currencies, as a real station has: SYP fuel sales and expenses, USD income
        // and expenses, a Sadcop transfer, a debt and its payment, and a SYP -> USD exchange.
        $tx(['type' => 'fuel_sale', 'fuel_type_id' => $petrol, 'tank_id' => $tank, 'liters' => 100, 'price_per_liter' => 15000, 'amount' => 1500000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 1, 'occurred_at' => '2026-09-02 09:00:00']);
        $tx(['type' => 'fuel_sale', 'fuel_type_id' => $petrol, 'tank_id' => $tank, 'liters' => 40, 'price_per_liter' => 15000, 'amount' => 600000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 136.7, 'occurred_at' => '2026-10-02 09:00:00']);
        $tx(['type' => 'other_income', 'amount' => 250, 'currency' => 'USD', 'occurred_at' => '2026-09-05 10:00:00']);
        $tx(['type' => 'expense', 'amount' => 75000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 1, 'occurred_at' => '2026-09-06 11:00:00']);
        $tx(['type' => 'expense', 'amount' => 40, 'currency' => 'USD', 'occurred_at' => '2026-10-03 11:00:00']);
        $sadcop = $tx(['type' => 'purchase', 'amount' => 3515000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 1, 'occurred_at' => '2026-09-02 08:10:12']);
        DB::table('sadcop_ledger_entries')->insert(['type' => 'deposit', 'transaction_id' => $sadcop, 'amount' => 3515000, 'recorded_by_id' => $this->userId, 'occurred_at' => '2026-09-02 08:10:12', 'created_at' => now(), 'updated_at' => now()]);
        $tx(['type' => 'currency_exchange', 'amount' => 13500000, 'currency' => 'SYP', 'to_amount' => 100000, 'to_currency' => 'USD', 'exchange_rate_to_usd' => 135, 'occurred_at' => '2026-10-04 09:00:00']);

        $debtor = DB::table('debtors')->insertGetId(['name' => 'Customer', 'created_at' => now(), 'updated_at' => now()]);
        $debt = DB::table('debts')->insertGetId(['direction' => 'receivable', 'debtor_id' => $debtor, 'amount' => 200000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 1, 'date' => '2026-09-10', 'status' => 'outstanding', 'recorded_by_id' => $this->userId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('debt_payments')->insert(['debt_id' => $debt, 'amount' => 50000, 'paid_at' => '2026-09-12 10:00:00', 'recorded_by_id' => $this->userId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function invokePrivate(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    /** Every figure the reports show, for September through today. */
    private function figures(): array
    {
        $from = Carbon::parse('2026-09-01')->startOfDay();
        $to = now()->endOfDay();

        $cashBox = app(CashBoxController::class);
        $statistics = app(StatisticsController::class);
        $earnings = app(EarningsController::class);
        $transactions = Transaction::query()->with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])->get();

        return [
            'cash_box_period' => $this->invokePrivate($cashBox, 'summarize', $from, $to, true, $this->userId),
            'cash_box_opening' => $this->invokePrivate($cashBox, 'summarize', Carbon::parse('2000-01-01'), Carbon::parse('2026-10-01')->subSecond(), true, $this->userId),
            'cash_box_history' => $this->invokePrivate($cashBox, 'historyEntries', $from, $to, true, $this->userId),
            'sadcop_balance' => SadcopLedgerEntry::currentBalanceSyp(),
            'sadcop_before_oct' => SadcopLedgerEntry::balanceSypBefore(Carbon::parse('2026-10-01')),
            'statistics' => $this->invokePrivate($statistics, 'summarize', $transactions),
            'statistics_shop' => $this->invokePrivate($statistics, 'shopSales', $transactions),
            'earnings_syp' => [
                $this->invokePrivate($earnings, 'fuelTypeBreakdown', $from, $to, 'SYP'),
                $this->invokePrivate($earnings, 'shopProfitSummary', $from, $to, 'SYP'),
                $this->invokePrivate($earnings, 'otherExpenses', $from, $to, 'SYP'),
            ],
            'earnings_usd' => [
                $this->invokePrivate($earnings, 'shopProfitSummary', $from, $to, 'USD'),
                $this->invokePrivate($earnings, 'otherExpenses', $from, $to, 'USD'),
            ],
            'annual' => app(AnnualFinancialSummary::class)->forYear(2026),
        ];
    }

    private function setRate(float $rate): void
    {
        DB::table('exchange_rates')->insert(['currency' => 'SYP', 'rate_to_usd' => $rate, 'set_by_id' => $this->userId, 'effective_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_setting_a_rate_for_the_first_time_changes_no_figure(): void
    {
        $before = $this->figures();

        $this->setRate(138);

        $this->assertEquals($before, $this->figures());
    }

    public function test_changing_the_rate_changes_no_figure(): void
    {
        $this->setRate(135);
        $before = $this->figures();

        $this->travel(1)->minutes();
        $this->setRate(138);

        $this->assertEquals($before, $this->figures());
    }

    public function test_figures_stay_in_their_own_currency(): void
    {
        $figures = $this->figures();
        $period = $figures['cash_box_period'];

        // Sadcop is a plain SYP sum; income and expenses keep each currency apart.
        $this->assertSame(3515000.0, (float) $period['sadcop_expense_syp']);
        $this->assertSame(250.0, (float) $period['income']['USD']);
        $this->assertSame(40.0, (float) $period['other_expense']['USD']);
        $this->assertSame(['SYP' => 2100000.0, 'USD' => 250.0], array_map('floatval', $figures['statistics']['income']));
        $this->assertSame(['SYP' => 3515000.0], array_map('floatval', $figures['statistics']['sadcop']));
        // The exchange moved 13,500,000 SYP out of the SYP box and 100,000 USD into the USD box.
        $this->assertSame(-13500000.0, (float) $period['exchanged']['SYP']);
        $this->assertSame(100000.0, (float) $period['exchanged']['USD']);
        // The annual summary has a table per currency, nothing converted between them.
        $this->assertSame(['SYP', 'USD'], array_keys($figures['annual']['currencies']));
        $this->assertSame(250.0, (float) $figures['annual']['currencies']['USD']['totals']['revenue']);
        $this->assertSame(2100000.0, (float) $figures['annual']['currencies']['SYP']['totals']['fuel_revenue']);
    }
}
