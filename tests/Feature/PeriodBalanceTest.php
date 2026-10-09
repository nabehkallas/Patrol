<?php

namespace Tests\Feature;

use App\Models\SadcopLedgerEntry;
use App\Models\Tank;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Balances for a picked date range: what an account or tank held when the range began, what
 * moved during it, and what it held when it ended.
 */
class PeriodBalanceTest extends TestCase
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

    private function transaction(array $values): int
    {
        return DB::table('transactions')->insertGetId([
            'user_id' => $this->userId,
            'amount' => 0,
            'currency' => 'SYP',
            'created_at' => now(),
            'updated_at' => now(),
            ...$values,
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

    public function test_a_tank_period_starts_where_the_previous_day_ended(): void
    {
        $fuelType = DB::table('fuel_types')->insertGetId(['name' => 'Diesel', 'slug' => 'diesel', 'created_at' => now(), 'updated_at' => now()]);
        $tankRow = fn (string $name) => DB::table('tanks')->insertGetId(['fuel_type_id' => $fuelType, 'name' => $name, 'capacity_liters' => 20000, 'created_at' => now(), 'updated_at' => now()]);
        $tankId = $tankRow('T1');
        $otherId = $tankRow('T2');
        $topUp = fn (float $liters, string $date) => DB::table('tank_top_ups')->insert(['tank_id' => $tankId, 'liters' => $liters, 'date' => $date, 'recorded_by_id' => $this->userId, 'created_at' => now(), 'updated_at' => now()]);

        $topUp(5000, '2026-08-25');
        $this->transaction(['type' => 'fuel_sale', 'tank_id' => $tankId, 'liters' => 1000, 'occurred_at' => '2026-08-31 22:00:00']);
        $this->transaction(['type' => 'fuel_delivery', 'tank_id' => $tankId, 'liters' => 3000, 'occurred_at' => '2026-09-01 08:00:00']);
        $this->transaction(['type' => 'fuel_sale', 'tank_id' => $tankId, 'liters' => 1500, 'occurred_at' => '2026-09-30 23:30:00']);
        DB::table('tank_transfers')->insert(['from_tank_id' => $tankId, 'to_tank_id' => $otherId, 'liters' => 200, 'date' => '2026-09-15', 'recorded_by_id' => $this->userId, 'created_at' => now(), 'updated_at' => now()]);
        $this->transaction(['type' => 'fuel_sale', 'tank_id' => $tankId, 'liters' => 100, 'occurred_at' => '2026-10-01 07:00:00']);

        $tank = Tank::findOrFail($tankId);

        $this->assertSame(
            ['starting' => 4000.0, 'in' => 3000.0, 'out' => 1700.0, 'ending' => 5300.0],
            $tank->periodSummary(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')),
        );
        $this->assertSame(5200.0, $tank->expectedLiters(), 'The live level still counts everything');
        $this->assertSame(200.0, Tank::findOrFail($otherId)->periodSummary(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))['ending']);
    }
}
