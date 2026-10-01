<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app used to run in UTC and now runs in the station's timezone (config app.timezone), so
 * entry-time stamps written before the switch hold UTC wall-clock times. Convert them to local
 * time. Only created_at/updated_at move: the date a record is filed under (occurred_at, date,
 * paid_at, effective_at, ...) already holds the day the user chose and is left untouched, so no
 * record changes day and no total changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(1);
    }

    public function down(): void
    {
        $this->shift(-1);
    }

    private function shift(int $direction): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $offsetSeconds = (new DateTimeZone(config('app.timezone')))
            ->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $minutes = $direction * intdiv($offsetSeconds, 60);

        if ($minutes === 0) {
            return;
        }

        $modifier = sprintf('%+d minutes', $minutes);

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (! Schema::hasColumns($name, ['created_at', 'updated_at'])) {
                continue;
            }

            foreach (['created_at', 'updated_at'] as $column) {
                DB::table($name)
                    ->whereNotNull($column)
                    ->update([$column => DB::raw("datetime({$column}, '{$modifier}')")]);
            }
        }
    }
};
