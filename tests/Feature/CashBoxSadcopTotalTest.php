<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Cash Box's "Sadcop payments" total is the sum of the Sadcop transfers it lists for the
 * range. Regression: SYP transfers saved before the station had an SYP rate carry rate 1; once a
 * rate of 138 was set, the total converted them out to dollars at 1 and back at 138, showing
 * 485,070,000 for a single 3,515,000 transfer.
 */
class CashBoxSadcopTotalTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        // The station's SYP rate, set after the transfers were recorded.
        DB::table('exchange_rates')->insert(['currency' => 'SYP', 'rate_to_usd' => 138, 'set_by_id' => $this->userId, 'effective_at' => '2026-10-10 01:57:14', 'created_at' => now(), 'updated_at' => now()]);
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
    }

    /** A Sadcop transfer as the app records it: a purchase transaction plus its ledger entry, in SYP at rate 1. */
    private function sadcopTransfer(float $amount, string $at): void
    {
        $id = DB::table('transactions')->insertGetId([
            'user_id' => $this->userId, 'type' => 'purchase', 'amount' => $amount, 'currency' => 'SYP',
            'exchange_rate_to_usd' => 1, 'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sadcop_ledger_entries')->insert([
            'type' => 'deposit', 'transaction_id' => $id, 'amount' => $amount, 'recorded_by_id' => $this->userId,
            'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{total: float, listed: float} */
    private function cashBoxFor(string $day): array
    {
        $controller = app(CashBoxController::class);
        $from = Carbon::parse($day)->startOfDay();
        $to = Carbon::parse($day)->endOfDay();

        $summary = (new ReflectionMethod($controller, 'summarize'))->invoke($controller, $from, $to, true, $this->userId, 138.0);
        $history = (new ReflectionMethod($controller, 'historyEntries'))->invoke($controller, $from, $to, true, $this->userId);

        return [
            'total' => (float) $summary['sadcop_expense_syp'],
            'listed' => (float) collect($history)->where('type', 'sadcop')->sum('amount'),
        ];
    }

    public function test_a_day_with_one_3515000_transfer_totals_3515000(): void
    {
        $this->sadcopTransfer(3515000, '2026-09-02 08:10:12');
        $this->sadcopTransfer(2106000, '2026-09-03 08:10:31');

        $this->assertSame(3515000.0, $this->cashBoxFor('2026-09-02')['total']);
    }

    public function test_the_total_equals_the_sum_of_the_listed_entries(): void
    {
        $this->sadcopTransfer(3515000, '2026-09-02 08:10:12');
        $this->sadcopTransfer(1250000, '2026-09-02 17:30:00');
        $this->sadcopTransfer(2106000, '2026-09-03 08:10:31');

        $cashBox = $this->cashBoxFor('2026-09-02');

        $this->assertSame($cashBox['listed'], $cashBox['total']);
        $this->assertSame(4765000.0, $cashBox['total']);
    }

    public function test_an_amount_already_in_the_target_currency_is_never_converted(): void
    {
        $transfer = new Transaction(['amount' => 3515000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 1]);

        $this->assertSame(3515000.0, $transfer->amountInSyp(138.0));
        $this->assertSame(3515000.0, (new Transaction(['amount' => 3515000, 'currency' => 'SYP', 'exchange_rate_to_usd' => 138]))->amountInSyp(138.0));
        // A dollar amount still converts at the given rate.
        $this->assertSame(1380.0, (new Transaction(['amount' => 10, 'currency' => 'USD']))->amountInSyp(138.0));
    }
}
