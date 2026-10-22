<?php

namespace Tests\Feature;

use App\Http\Controllers\ShopController;
use App\Models\ShopItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A Store sale is priced on the server: quantity × the item's sell price on the sale's date, in
 * that price's currency. Only an admin may record another amount (a discount), and a later
 * price change reprices only the sales still at their list price.
 */
class ShopSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $attendant;

    private ShopItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('attendant', 'web');
        $this->admin = $this->user('admin');
        $this->attendant = $this->user('attendant');

        $this->item = ShopItem::create(['name' => 'زيت']);
        $this->price(1000, 'SYP', '2026-10-01');
        Transaction::create(['user_id' => $this->admin->id, 'type' => 'purchase', 'shop_item_id' => $this->item->id, 'quantity' => 50, 'description' => 'زيت × 50', 'amount' => 1, 'currency' => 'SYP', 'occurred_at' => '2026-09-30 10:00:00']);
    }

    private function user(string $role): User
    {
        $id = DB::table('users')->insertGetId(['name' => $role, 'email' => "$role@example.test", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::findOrFail($id);
        $user->assignRole($role);

        return $user;
    }

    private function price(float $sell, string $currency, string $from): void
    {
        $this->item->prices()->create(['base_price' => $sell / 2, 'sell_price' => $sell, 'currency' => $currency, 'set_by_id' => $this->admin->id, 'effective_at' => $from]);
    }

    private function sell(User $user, array $input): Transaction
    {
        $request = Request::create('/shop/sales', 'POST', ['shop_item_id' => $this->item->id, 'quantity' => 3, 'date' => '2026-10-21', ...$input]);
        $request->setUserResolver(fn () => $user);
        app(ShopController::class)->storeSale($request);

        return Transaction::where('type', 'other_income')->latest('id')->firstOrFail();
    }

    /** @return array{0: float, 1: string} */
    private function money(Transaction $sale): array
    {
        return [(float) $sale->fresh()->amount, $sale->fresh()->currency];
    }

    public function test_an_attendants_amount_and_currency_are_ignored(): void
    {
        $sale = $this->sell($this->attendant, ['amount' => 1, 'currency' => 'USD']);

        $this->assertSame([3000.0, 'SYP'], $this->money($sale));
    }

    public function test_a_sale_with_no_amount_is_priced_from_the_item(): void
    {
        $this->assertSame([3000.0, 'SYP'], $this->money($this->sell($this->attendant, [])));
        $this->assertSame([3000.0, 'SYP'], $this->money($this->sell($this->admin, [])));
    }

    public function test_an_admin_can_record_a_discount_but_not_another_currency(): void
    {
        $sale = $this->sell($this->admin, ['amount' => 2500, 'currency' => 'USD']);

        $this->assertSame([2500.0, 'SYP'], $this->money($sale));
    }

    public function test_a_backdated_sale_uses_the_price_of_its_date(): void
    {
        $this->price(1200, 'SYP', '2026-10-15');

        // More than 7 days back is an admin's (EntryDate).
        $this->assertSame([3000.0, 'SYP'], $this->money($this->sell($this->admin, ['date' => '2026-10-10'])));
        $this->assertSame([3600.0, 'SYP'], $this->money($this->sell($this->attendant, ['date' => '2026-10-20'])));
    }

    public function test_an_attendant_cannot_date_a_sale_more_than_seven_days_back_or_in_the_future(): void
    {
        foreach (['2026-10-13', '2026-10-22'] as $date) {
            try {
                $this->sell($this->attendant, ['date' => $date]);
                $this->fail("A sale dated $date was saved.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('date', $e->errors());
            }
        }

        $this->assertSame([3000.0, 'SYP'], $this->money($this->sell($this->attendant, ['date' => '2026-10-14'])));
    }

    public function test_an_item_without_a_price_on_that_date_cannot_be_sold(): void
    {
        try {
            $this->sell($this->admin, ['date' => '2026-09-30']);
            $this->fail('The sale was saved without a price.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(0, Transaction::where('type', 'other_income')->count());
    }

    public function test_a_backdated_price_change_keeps_discounts_and_moves_the_currency(): void
    {
        $listed = $this->sell($this->attendant, ['date' => '2026-10-20']);
        $discounted = $this->sell($this->admin, ['date' => '2026-10-20', 'amount' => 2500]);

        $request = Request::create('/shop/items/'.$this->item->id, 'PATCH', ['name' => 'زيت', 'base_price' => 1, 'sell_price' => 2, 'currency' => 'USD', 'effective_at' => '2026-10-18']);
        $request->setUserResolver(fn () => $this->admin);
        app(ShopController::class)->updateItem($request, $this->item);

        $this->assertSame([6.0, 'USD'], $this->money($listed));
        $this->assertSame([2500.0, 'SYP'], $this->money($discounted));
    }
}
