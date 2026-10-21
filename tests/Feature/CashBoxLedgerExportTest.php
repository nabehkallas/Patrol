<?php

namespace Tests\Feature;

use App\Http\Controllers\CashBoxController;
use App\Models\CashBoxOpening;
use App\Models\Debt;
use App\Models\Debtor;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * The Cash Box Excel export: a column set per currency (USD income included), opening cash as
 * rows of its own, and running balances that end exactly where the Cash Box page does.
 */
class CashBoxLedgerExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 12:00:00'));
        $id = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::findOrFail($id);
        $tx = fn (array $row) => Transaction::create(['user_id' => $id, ...$row]);

        // Before the sheet: SYP sales and a USD income.
        $tx(['type' => 'other_income', 'other_income_category' => 'cash_box', 'description' => 'Sales', 'amount' => 500000, 'currency' => 'SYP', 'occurred_at' => '2026-10-10 10:00']);
        $tx(['type' => 'other_income', 'other_income_category' => 'other', 'description' => 'Rent', 'amount' => 40, 'currency' => 'USD', 'occurred_at' => '2026-10-11 10:00']);
        // In it: sales, a government entry, USD income and expense, an exchange, and a payment.
        $tx(['type' => 'other_income', 'other_income_category' => 'cash_box', 'description' => 'Sales', 'amount' => 300000, 'currency' => 'SYP', 'occurred_at' => '2026-10-19 10:00']);
        $tx(['type' => 'other_income', 'other_income_category' => 'government', 'description' => 'Smart card', 'notes' => 'بطاقة', 'amount' => 70000, 'currency' => 'SYP', 'occurred_at' => '2026-10-19 11:00']);
        $tx(['type' => 'other_income', 'other_income_category' => 'cash_box', 'description' => 'Tip', 'amount' => 25, 'currency' => 'USD', 'occurred_at' => '2026-10-20 10:00']);
        $tx(['type' => 'expense', 'description' => 'Parts', 'amount' => 15, 'currency' => 'USD', 'occurred_at' => '2026-10-20 11:00']);
        $tx(['type' => 'expense', 'description' => 'Repairs', 'amount' => 20000, 'currency' => 'SYP', 'occurred_at' => '2026-10-20 12:00']);
        $tx(['type' => 'currency_exchange', 'amount' => 139000, 'currency' => 'SYP', 'to_amount' => 10, 'to_currency' => 'USD', 'exchange_rate_to_usd' => 13900, 'occurred_at' => '2026-10-21 09:00']);
        $debt = Debt::create(['direction' => 'payable', 'debtor_id' => Debtor::create(['name' => 'المورد'])->id, 'amount' => 30, 'currency' => 'USD', 'date' => '2026-10-15', 'status' => 'outstanding', 'recorded_by_id' => $id]);
        $debt->recordPayment(30, $id, null, Carbon::parse('2026-10-21 10:00'), ['cash_amount' => 4170, 'cash_currency' => 'SYP', 'cash_rate' => 139]);
        // Opening cash: one before the sheet, one inside it.
        CashBoxOpening::create(['currency' => 'SYP', 'amount' => 1000000, 'date' => '2026-10-01', 'recorded_by_id' => $id]);
        CashBoxOpening::create(['currency' => 'USD', 'amount' => 200, 'date' => '2026-10-20', 'recorded_by_id' => $id, 'notes' => 'الدرج']);
    }

    /** @return array{headers: list<string>, rows: list<list<mixed>>} the sheet with its formulas worked out */
    private function sheet(): array
    {
        $sheet = app(CashBoxController::class)->ledgerSheet(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-21'), true, $this->user->id);
        $book = (new Spreadsheet)->getActiveSheet();
        foreach ($sheet['rows'] as $i => $row) {
            foreach ($row as $col => $cell) {
                $book->setCellValue([$col + 1, $i + 5], $cell);
            }
        }
        $rows = [];
        foreach (array_keys($sheet['rows']) as $i) {
            $rows[] = array_map(fn (int $col) => $book->getCell([$col + 1, $i + 5])->getCalculatedValue(), array_keys($sheet['headers']));
        }

        return ['headers' => $sheet['headers'], 'rows' => $rows];
    }

    public function test_every_currency_has_its_columns_and_opening_cash_its_own_row(): void
    {
        $sheet = $this->sheet();

        $this->assertSame([
            'Date', 'Cash Box (SYP)', 'Sold (SYP)', 'Sadcop Payments', 'Government', 'Other Income', 'Exchanged (SYP)', 'Expense (SYP)',
            'Cash Box (USD)', 'Income (USD)', 'Exchanged (USD)', 'Expense (USD)', 'Notes',
        ], $sheet['headers']);

        // 19th, the USD opening row on the 20th, the 20th, the 21st.
        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-20', '2026-10-21'], array_column($sheet['rows'], 0));
        $opening = $sheet['rows'][1];
        $this->assertSame('Opening cash (USD) الدرج', $opening[12]);
        $this->assertEquals([200, null, null], [$opening[9], $opening[10], $opening[11]]);
        $this->assertNull($opening[2]);

        // The 20th: USD income 25 and expense 15 in their own columns.
        $this->assertEquals([25, 15], [$sheet['rows'][2][9], $sheet['rows'][2][11]]);
        // The 19th: government apart from sales, with its note.
        $this->assertEquals([300000, 70000, 'بطاقة'], [$sheet['rows'][0][2], $sheet['rows'][0][4], $sheet['rows'][0][12]]);
    }

    public function test_the_balances_run_like_the_cash_box(): void
    {
        $sheet = $this->sheet();
        $rows = $sheet['rows'];

        // Carried in: 1,000,000 opening + 500,000 sales; 40 USD.
        $this->assertEquals([1500000, 40], [$rows[0][1], $rows[0][8]]);

        // After the last row's own figures, each box ends where the Cash Box page does.
        $last = end($rows);
        $closingSyp = $last[1] + $last[2] - $last[3] + $last[4] + $last[5] + $last[6] - $last[7];
        $closingUsd = $last[8] + $last[9] + $last[10] - $last[11];
        $page = app(CashBoxController::class)->summarize(Carbon::parse('2000-01-01'), now()->endOfDay(), true, $this->user->id)['net'];

        $this->assertEquals($page['SYP'], $closingSyp);
        $this->assertEquals($page['USD'], $closingUsd);
        // 1.5M + 300k + 70k - 20k - 139k exchanged - 4,170 paid; 40 + 200 + 25 - 15 + 10.
        $this->assertEquals([1706830, 260], [$closingSyp, $closingUsd]);
    }
}
