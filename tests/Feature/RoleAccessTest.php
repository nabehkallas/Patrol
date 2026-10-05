<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\User;
use App\Policies\DebtPolicy;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Which station screens and actions are admin-only. Attendants run the day (cash box, pump
 * counters, inventory, shop, Sadcop) and can record debts and collect payments on them;
 * management reports, station setup and changing or deleting debts/debtors are for admins.
 */
class RoleAccessTest extends TestCase
{
    private function isAdminOnly(string $name): bool
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "Route [{$name}] does not exist");

        return in_array('role:admin', $route->gatherMiddleware(), true);
    }

    public function test_management_reports_and_station_setup_are_admin_only(): void
    {
        $adminOnly = [
            // Management reports
            'admin.earnings.index', 'admin.earnings.export-xlsx',
            'statistics.index', 'statistics.export-pdf', 'statistics.annual.unlock', 'statistics.annual.lock',
            'admin.fuel-prices.index', 'admin.fuel-prices.store',
            'admin.exchange-rates.index', 'admin.exchange-rates.store',
            'admin.users.index', 'admin.users.store', 'admin.users.destroy',
            // Station setup
            'admin.tanks.index', 'admin.tanks.store',
            'admin.fuel-pumps.index', 'admin.fuel-pumps.store',
            'admin.fuel-types.index', 'admin.fuel-types.store',
            'tools.tank-volume',
        ];

        foreach ($adminOnly as $name) {
            $this->assertTrue($this->isAdminOnly($name), "[{$name}] should be admin-only");
        }
    }

    public function test_changing_or_deleting_debts_and_debtors_is_admin_only(): void
    {
        foreach ([
            'debts.edit', 'debts.update', 'debts.destroy', 'debts.transfer', 'debts.settle-filtered',
            'debtors.edit', 'debtors.update', 'debtors.destroy',
        ] as $name) {
            $this->assertTrue($this->isAdminOnly($name), "[{$name}] should be admin-only");
        }
    }

    public function test_everyone_can_see_balances_record_debts_and_collect_payments(): void
    {
        foreach ([
            'cash-box.index', 'pump-counters.index', 'inventory.index', 'shop.index', 'sadcop.index',
            'debts.index', 'debts.create', 'debts.store', 'debts.settle', 'debts.payments.store',
            'debtors.index', 'debtors.create', 'debtors.store', 'debtors.settle-all',
            'transactions.index',
        ] as $name) {
            $this->assertFalse($this->isAdminOnly($name), "[{$name}] should be open to attendants");
        }
    }

    public function test_the_debt_policy_matches_the_routes(): void
    {
        $policy = new DebtPolicy;
        $debt = new Debt;

        $attendant = \Mockery::mock(User::class)->makePartial();
        $attendant->shouldReceive('isAdmin')->andReturn(false);
        $attendant->id = 7;
        $debt->recorded_by_id = 7;

        $this->assertTrue($policy->settle($attendant, $debt), 'Attendants collect payments');
        $this->assertFalse($policy->update($attendant, $debt), 'Even on a debt they recorded');
        $this->assertFalse($policy->delete($attendant, $debt));

        $admin = \Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('isAdmin')->andReturn(true);

        $this->assertTrue($policy->settle($admin, $debt));
        $this->assertTrue($policy->update($admin, $debt));
        $this->assertTrue($policy->delete($admin, $debt));
    }
}
