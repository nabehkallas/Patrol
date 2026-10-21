<?php
// READ-ONLY smoke check after a deploy: on every station, the main pages' calculations run
// without an error -- cash box balances and today's summary, the pump counter entry table for
// today, statistics for the last 7 days, this month's Cash Box Excel sheet. Prints one line per
// station: "ok" with counts, or the first error.
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\StatisticsController;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashBox;

$call = fn (object $object, string $method, ...$args) => (new ReflectionMethod($object, $method))->invoke($object, ...$args);

foreach (Tenant::all() as $tenant) {
    tenancy()->initialize($tenant);
    try {
        $admin = User::role('admin')->value('id') ?? User::value('id');
        $cashBox = app(CashBoxController::class);
        $balances = app(CashBox::class)->balances();
        $call($cashBox, 'summarize', now()->startOfDay(), now()->endOfDay(), true, $admin);
        $sheet = $cashBox->ledgerSheet(now()->startOfMonth(), now(), true, $admin);
        $entry = $call(app(PumpCounterReadingController::class), 'entryContext', now()->toDateString(), Shift::defaultShift(), false);
        $week = Transaction::with(['user', 'fuelType', 'tank', 'debt', 'sadcopLedgerEntry', 'shopItem'])->where('occurred_at', '>=', now()->subDays(7))->get();
        $call(app(StatisticsController::class), 'summarize', $week);
        echo $tenant->name, ': ok | balances ', json_encode($balances), ' | pumps ', count($entry['pumps']), ' | sheet rows ', count($sheet['rows']), "\n";
    } catch (Throwable $e) {
        echo $tenant->name, ': ERROR ', get_class($e), ': ', $e->getMessage(), ' at ', basename($e->getFile()), ':', $e->getLine(), "\n";
    }
    tenancy()->end();
}
