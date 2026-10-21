<?php
// READ-ONLY. A fingerprint of every station: stored rows and report figures, hashed per part.
// Run it before a deploy (old code) and after (new code); fp-compare.php then names any part
// that changed. FP_IGNORE (comma list) leaves out what the new code adds on purpose: new
// columns of the stored rows, new keys of the Cash Box summary or of its history rows.
// FP_YEAR picks the year the reports cover (this year by default).
use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\StatisticsController;
use App\Models\Tank;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnnualFinancialSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$ignore = array_filter(explode(',', (string) getenv('FP_IGNORE')));
$year = (int) (getenv('FP_YEAR') ?: now()->year);
$call = fn (object $object, string $method, ...$args) => (new ReflectionMethod($object, $method))->invoke($object, ...$args);
$tables = ['transactions', 'debts', 'debt_payments', 'pump_counter_readings', 'fuel_cost_allocations', 'tank_top_ups', 'shifts', 'cash_box_openings'];
$out = [];

foreach (Tenant::all() as $tenant) {
    tenancy()->initialize($tenant);
    $admin = User::role('admin')->value('id') ?? User::value('id');
    $from = Carbon::create($year)->startOfYear();
    $to = Carbon::create($year)->endOfYear();
    $cashBox = app(CashBoxController::class);
    $earnings = app(EarningsController::class);
    $parts = [];

    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            $parts["rows.$table"] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => collect((array) $row)->except($ignore)->all())->all();
        }
    }

    // The balance carried to the end of every day with activity, per currency.
    $days = Transaction::selectRaw('date(occurred_at) as d')->distinct()->pluck('d')
        ->merge(DB::table('debt_payments')->selectRaw('date(paid_at) as d')->distinct()->pluck('d'))
        ->unique()->sort()->values();
    $parts['cash_box.balances'] = $days->mapWithKeys(fn ($day) => [
        $day => $call($cashBox, 'summarize', Carbon::parse('2000-01-01'), Carbon::parse($day)->endOfDay(), true, $admin)['net'],
    ])->all();
    $parts['cash_box.summary'] = collect($call($cashBox, 'summarize', $from, $to, true, $admin))->except($ignore)->all();
    $parts['cash_box.history'] = collect($call($cashBox, 'historyEntries', $from, $to, true, $admin))
        ->map(fn (array $row) => collect($row)->except($ignore)->all())->all();

    $transactions = Transaction::with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])->get();
    $parts['statistics'] = $call(app(StatisticsController::class), 'summarize', $transactions);
    $parts['earnings.fuel'] = $call($earnings, 'fuelTypeBreakdown', $from, $to, 'SYP');
    $parts['earnings.shop'] = $call($earnings, 'shopProfitSummary', $from, $to, 'SYP');
    $parts['earnings.other_expenses'] = $call($earnings, 'otherExpenses', $from, $to, 'SYP');
    $parts['annual'] = app(AnnualFinancialSummary::class)->forYear($year);
    $parts['stock'] = Tank::orderBy('id')->get()->map(fn (Tank $tank) => round($tank->expectedLiters(), 3))->all();

    $station = [
        'station' => $tenant->name,
        'counts' => collect($tables)->filter(fn ($t) => Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
        'balance_now' => $days->isNotEmpty() ? $parts['cash_box.balances'][$days->last()] : null,
    ];
    foreach ($parts as $name => $value) {
        $station['hash'][$name] = substr(md5(json_encode($value)), 0, 12);
    }

    $out[] = $station;
    tenancy()->end();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
