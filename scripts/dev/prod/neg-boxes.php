<?php
// READ-ONLY. Cash box balance per currency for every station (the Cash Box page's own sum:
// CashBoxController::summarize() from the start until now), and every day on which a box
// went (or stayed) below zero, with that day's records in the currency concerned.
use App\Http\Controllers\CashBoxController;
use App\Models\DebtPayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

$cash = app(CashBoxController::class);
$summarize = fn ($from, $to, int $user) => (new ReflectionMethod($cash, 'summarize'))->invoke($cash, $from, $to, true, $user)['net'];

foreach (Tenant::all() as $tenant) {
    tenancy()->initialize($tenant);
    $user = User::value('id') ?? 0;
    $start = Carbon::parse('2000-01-01');
    $now = now();
    echo "== ", $tenant->name, ": balance now ", json_encode($summarize($start, $now, $user)), "\n";

    $days = Transaction::selectRaw('date(occurred_at) as d')->distinct()->pluck('d')
        ->merge(DebtPayment::selectRaw('date(paid_at) as d')->distinct()->pluck('d'))
        ->unique()->sort()->values();
    $running = [];
    $negative = [];
    foreach ($days as $day) {
        $net = $summarize(Carbon::parse($day)->startOfDay(), Carbon::parse($day)->endOfDay(), $user);
        foreach ($net as $currency => $value) {
            $running[$currency] = round(($running[$currency] ?? 0) + $value, 2);
            if ($running[$currency] < -0.005) {
                $negative[$currency][] = "$day: ".number_format($running[$currency], 2);
            }
        }
    }
    foreach ($negative as $currency => $list) {
        echo "  $currency below zero on ", count($list), ' day(s): ', implode(' | ', array_slice($list, 0, 8)), count($list) > 8 ? ' …' : '', "\n";
        $first = substr($list[0], 0, 10);
        foreach (Transaction::with(['shopItem', 'debt', 'sadcopLedgerEntry'])->where('currency', $currency)->whereDate('occurred_at', '<=', $first)->orderBy('occurred_at')->get() as $t) {
            echo '    #', $t->id, ' ', $t->occurred_at->toDateString(), ' ', $t->type->value, ' ', $t->amount, ' ', $t->currency,
                $t->shopItem ? ' item='.$t->shopItem->name : '', $t->debt ? ' (debt)' : '', ' — ', $t->description ?? '', ' ', $t->notes ?? '', "\n";
        }
    }
    if (! $negative) {
        echo "  no box ever below zero\n";
    }
    tenancy()->end();
}
