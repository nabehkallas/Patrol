<?php

namespace Tests\Feature;

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Middleware\LimitExportRange;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Requests\StoreDebtRequest;
use App\Http\Requests\StoreSadcopDeliveryRequest;
use App\Http\Requests\StoreTankTopUpRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Debtor;
use App\Models\User;
use App\Services\CashBox;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * What every form recording money or fuel checks on the server: the entry date (never in the
 * future; non-admins at most 7 days back), upper bounds, liters × price, a fuel debt's currency,
 * the rate a non-admin may pay at, export limits, and what a station user can change in their
 * own profile.
 */
class EntryRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $attendant;

    private int $tankId;

    private int $fuelTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('attendant', 'web');
        $this->admin = $this->user('admin');
        $this->attendant = $this->user('attendant');

        $this->fuelTypeId = DB::table('fuel_types')->insertGetId(['name' => 'بنزين', 'slug' => 'petrol', 'created_at' => now(), 'updated_at' => now()]);
        $this->tankId = DB::table('tanks')->insertGetId(['fuel_type_id' => $this->fuelTypeId, 'name' => 'بنزين اساسي', 'capacity_liters' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fuel_prices')->insert(['fuel_type_id' => $this->fuelTypeId, 'price_per_liter' => 2, 'currency' => 'USD', 'set_by_id' => $this->admin->id, 'effective_at' => '2026-10-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('exchange_rates')->insert(['currency' => 'SYP', 'rate_to_usd' => 139, 'set_by_id' => $this->admin->id, 'effective_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        tenancy()->initialized = false;

        parent::tearDown();
    }

    private function user(string $role): User
    {
        $id = DB::table('users')->insertGetId(['name' => $role, 'email' => "$role@example.test", 'password' => bcrypt('secret'), 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $user = User::findOrFail($id);
        $user->assignRole($role);

        return $user;
    }

    /**
     * The validation errors a form request gives, by field (empty when it passes).
     *
     * @param  class-string<FormRequest>  $form
     * @return array<string, list<string>>
     */
    private function errors(string $form, array $input, ?User $user = null): array
    {
        return $this->resolve($form, $input, $user)[1];
    }

    /**
     * @param  class-string<FormRequest>  $form
     * @return array{0: FormRequest, 1: array<string, list<string>>}
     */
    private function resolve(string $form, array $input, ?User $user = null): array
    {
        $request = $form::create('/x', 'POST', $input);
        $request->setUserResolver(fn () => $user ?? $this->attendant);
        $request->setContainer(app())->setRedirector(app('redirect'));

        try {
            $request->validateResolved();

            return [$request, []];
        } catch (ValidationException $e) {
            return [$request, $e->errors()];
        }
    }

    private function topUp(string $date, User $user): array
    {
        return $this->errors(StoreTankTopUpRequest::class, ['tank_id' => $this->tankId, 'liters' => 100, 'date' => $date], $user);
    }

    public function test_an_entry_date_is_never_in_the_future_and_only_admins_go_back_further_than_seven_days(): void
    {
        $this->assertSame([], $this->topUp('2026-10-21', $this->attendant));
        $this->assertSame([], $this->topUp('2026-10-14', $this->attendant));
        $this->assertArrayHasKey('date', $this->topUp('2026-10-13', $this->attendant));
        $this->assertArrayHasKey('date', $this->topUp('2026-10-22', $this->attendant));

        $this->assertSame([], $this->topUp('2025-01-01', $this->admin));
        $this->assertArrayHasKey('date', $this->topUp('2026-10-22', $this->admin));
    }

    public function test_amounts_and_notes_have_upper_bounds(): void
    {
        $expense = ['type' => 'expense', 'description' => 'Repairs', 'currency' => 'SYP', 'occurred_at' => '2026-10-21'];

        $this->assertSame([], $this->errors(StoreTransactionRequest::class, [...$expense, 'amount' => 1_000_000_000_000]));
        $this->assertArrayHasKey('amount', $this->errors(StoreTransactionRequest::class, [...$expense, 'amount' => 1_000_000_000_001]));
        $this->assertArrayHasKey('notes', $this->errors(StoreTransactionRequest::class, [...$expense, 'amount' => 10, 'notes' => str_repeat('x', 1001)]));
    }

    public function test_a_fuel_delivery_amount_is_liters_times_price(): void
    {
        $delivery = fn (array $input) => $this->errors(StoreTransactionRequest::class, ['type' => 'fuel_delivery', 'tank_id' => $this->tankId, 'liters' => 1000, 'price_per_liter' => 10000, 'currency' => 'SYP', 'occurred_at' => '2026-10-21', ...$input]);

        $this->assertSame([], $delivery(['amount' => 10_000_000]));
        $this->assertSame([], $delivery(['amount' => 10_005_000])); // within 0.1%
        $this->assertArrayHasKey('amount', $delivery(['amount' => 9_000_000]));
        $this->assertArrayHasKey('price_per_liter', $delivery(['amount' => 10_000_000, 'price_per_liter' => null]));
    }

    public function test_a_fuel_debt_is_in_its_fuel_price_currency_and_matches_liters_times_price(): void
    {
        $debt = ['direction' => 'receivable', 'debtor_id' => Debtor::create(['name' => 'زبون'])->id, 'fuel_type_id' => $this->fuelTypeId, 'liters' => 50, 'price_per_liter' => 2, 'date' => '2026-10-21'];

        [$request, $errors] = $this->resolve(StoreDebtRequest::class, [...$debt, 'amount' => 100, 'currency' => 'SYP']);
        $this->assertSame([], $errors);
        $this->assertSame('USD', $request->validated('currency'));

        $this->assertArrayHasKey('amount', $this->errors(StoreDebtRequest::class, [...$debt, 'amount' => 80, 'currency' => 'USD']));
    }

    public function test_a_sadcop_delivery_amount_is_liters_times_price(): void
    {
        $delivery = fn (float $amount) => $this->errors(StoreSadcopDeliveryRequest::class, ['tank_id' => $this->tankId, 'liters' => 1000, 'price_per_liter' => 9500, 'amount' => $amount, 'occurred_at' => '2026-10-21']);

        $this->assertSame([], $delivery(9_500_000));
        $this->assertArrayHasKey('amount', $delivery(5_000_000));
    }

    public function test_a_non_admin_pays_from_the_primary_box_only_near_todays_rate(): void
    {
        $pay = fn (float $rate, bool $admin, string $currency = 'USD') => app(CashBox::class)->moneyOut(50, $currency, CashBox::FROM_PRIMARY, $rate, true, anyRate: $admin);

        $this->assertSame(7500.0, $pay(150, false)['amount']); // 139 + 7.9%
        $this->assertSame(8000.0, $pay(160, true)['amount']);

        foreach ([[160, 'USD'], [120, 'USD'], [5, 'TRY']] as [$rate, $currency]) {
            try {
                $pay($rate, false, $currency); // 15% off, 14% off, and no saved TRY rate
                $this->fail("Paid $currency at $rate.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('cash_rate', $e->errors());
            }
        }
    }

    public function test_every_export_is_throttled_and_range_limited(): void
    {
        $exports = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains((string) $route->getName(), 'export-'));

        $this->assertGreaterThanOrEqual(20, $exports->count());

        foreach ($exports as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('throttle:exports', $middleware, $route->getName());
            $this->assertContains(LimitExportRange::class, $middleware, $route->getName());
        }
    }

    public function test_an_export_covers_at_most_two_years(): void
    {
        $this->withoutVite();
        $export = fn (array $query) => (new LimitExportRange)->handle(Request::create('/x', 'GET', $query), fn () => response('file'))->getStatusCode();

        $this->assertSame(200, $export(['from' => '2024-10-21', 'to' => '2026-10-21']));
        $this->assertSame(200, $export([]));
        $this->assertSame(422, $export(['from' => '2024-10-20', 'to' => '2026-10-21']));
        $this->assertSame(422, $export(['from' => '2020-01-01']));
    }

    public function test_a_station_user_cannot_change_their_email_or_delete_themselves(): void
    {
        tenancy()->initialized = true;
        $this->actingAs($this->attendant);

        [$request, $errors] = $this->resolve(ProfileUpdateRequest::class, ['name' => 'New name', 'email' => 'other@example.test']);
        $this->assertSame([], $errors);
        app(ProfileController::class)->update($request);
        $this->assertSame(['New name', 'attendant@example.test'], [$this->attendant->fresh()->name, $this->attendant->fresh()->email]);

        try {
            app(ProfileController::class)->destroy(ProfileDeleteRequest::create('/x', 'DELETE', ['password' => 'secret']));
            $this->fail('A station user deleted their own account.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNotNull($this->attendant->fresh());
    }
}
