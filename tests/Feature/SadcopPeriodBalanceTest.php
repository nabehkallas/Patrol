<?php

namespace Tests\Feature;

use App\Models\SadcopLedgerEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Sadcop balance as it stood at the end of a picked date range. */
class SadcopPeriodBalanceTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = DB::table('users')->insertGetId([
            'name' => 'Tester',
            'email' => 'tester@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sadcop(string $type, float $amount, string $at): void
    {
        DB::table('sadcop_ledger_entries')->insert([
            'type' => $type,
            'amount' => $amount,
            'recorded_by_id' => $this->userId,
            'occurred_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sadcop_balance_before_a_moment_counts_only_earlier_entries(): void
    {
        $this->sadcop('opening', 1000, '2026-08-20 09:00:00');
        $this->sadcop('deposit', 500, '2026-09-05 10:00:00');
        $this->sadcop('delivery', 300, '2026-09-10 12:00:00');
        $this->sadcop('deposit', 200, '2026-10-02 08:00:00');

        $this->assertSame(0.0, SadcopLedgerEntry::balanceSypBefore(Carbon::parse('2026-08-01')));
        $this->assertSame(1000.0, SadcopLedgerEntry::balanceSypBefore(Carbon::parse('2026-09-01')));
        $this->assertSame(1200.0, SadcopLedgerEntry::balanceSypBefore(Carbon::parse('2026-10-01')), 'September ends at 1000 + 500 - 300');
        $this->assertSame(1400.0, SadcopLedgerEntry::currentBalanceSyp());
    }
}
