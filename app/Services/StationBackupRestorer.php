<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PDO;
use Throwable;

/**
 * Restores a station from a backup made with "Download backup" (Settings > Station Data):
 * a copy of the station's SQLite database. Only the station's data is replaced: fuel types,
 * tanks, pumps, prices, transactions, inventory, debts, shop, Sadcop and so on. Who can sign
 * in (users, roles, permissions) and framework tables are kept as they are now, so a restore
 * can never lock the current admin out or bring back accounts the login directory doesn't
 * know about (the same split Reset uses).
 *
 * Before anything is replaced, the current database is snapshotted next to it, so a restore
 * done by mistake can be undone from that file. The copy runs in one transaction: if any table
 * fails to copy, nothing changes.
 */
class StationBackupRestorer
{
    /** Kept from the live database, never taken from the backup. */
    private const KEEP = [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
        'role_has_permissions', 'password_reset_tokens', 'sessions', 'migrations',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sqlite_sequence',
    ];

    /** A file without these isn't a station backup. */
    private const REQUIRED = ['migrations', 'fuel_types', 'tanks', 'transactions'];

    /**
     * @return array{tables: int, rows: int, snapshot: string}
     */
    public function restore(UploadedFile $file): array
    {
        $backupPath = $file->getRealPath();
        $this->assertValidBackup($backupPath);

        $snapshot = $this->snapshotCurrent();

        DB::statement('ATTACH DATABASE ? AS backup', [$backupPath]);
        Schema::disableForeignKeyConstraints();

        try {
            $backupTables = $this->tables('backup');
            $tables = 0;
            $rows = 0;

            DB::transaction(function () use ($backupTables, &$tables, &$rows) {
                foreach ($this->tables('main') as $table) {
                    if (in_array($table, self::KEEP, true)) {
                        continue;
                    }

                    DB::statement("DELETE FROM main.\"{$table}\"");
                    $tables++;

                    // A table the backup doesn't have (added by a later update) is left empty,
                    // so the station matches the backup exactly.
                    if (! in_array($table, $backupTables, true)) {
                        continue;
                    }

                    // Only columns both sides have; anything newer keeps its default.
                    $columns = array_values(array_intersect($this->columns('main', $table), $this->columns('backup', $table)));
                    $list = implode(', ', array_map(fn (string $c) => "\"{$c}\"", $columns));

                    DB::statement("INSERT INTO main.\"{$table}\" ({$list}) SELECT {$list} FROM backup.\"{$table}\"");
                    $rows += (int) DB::scalar("SELECT COUNT(*) FROM main.\"{$table}\"");
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
            DB::statement('DETACH DATABASE backup');
        }

        return ['tables' => $tables, 'rows' => $rows, 'snapshot' => basename($snapshot)];
    }

    private function assertValidBackup(string $path): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['backup' => $message]);

        $handle = fopen($path, 'rb');
        $header = $handle ? fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($header !== "SQLite format 3\0") {
            $fail(__('This file is not a station backup. Choose a file made with "Download backup".'));
        }

        try {
            $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // ERRMODE_EXCEPTION: a failed query throws rather than returning false.
            $column = fn (string $sql): array => ($pdo->query($sql) ?: throw new \RuntimeException($sql))->fetchAll(PDO::FETCH_COLUMN);
            $integrity = $column('PRAGMA integrity_check')[0] ?? null;
            $tables = $column("SELECT name FROM sqlite_master WHERE type = 'table'");
            $backupMigrations = in_array('migrations', $tables, true) ? $column('SELECT migration FROM migrations') : [];
        } catch (Throwable) {
            $fail(__('The backup file could not be read. It may be damaged.'));
        }

        if ($integrity !== 'ok') {
            $fail(__('The backup file is damaged and cannot be restored.'));
        }

        if (array_diff(self::REQUIRED, $tables) !== []) {
            $fail(__('This file is not a station backup. Choose a file made with "Download backup".'));
        }

        // A backup taken on a newer version of the app may hold data this version can't place.
        $known = DB::table('migrations')->pluck('migration')->all();
        if (array_diff($backupMigrations, $known) !== []) {
            $fail(__('This backup was made by a newer version of the system and cannot be restored here.'));
        }
    }

    /** Copies the live database aside before it is overwritten. */
    private function snapshotCurrent(): string
    {
        $dir = dirname((string) config('database.connections.tenant.database')).'/restore-snapshots';

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/'.tenant('id').'-before-restore-'.now()->format('Ymd-His').'.sqlite';
        DB::statement('VACUUM INTO ?', [$path]);

        return $path;
    }

    /**
     * @return list<string>
     */
    private function tables(string $schema): array
    {
        return array_values(array_map(
            fn (\stdClass $row): string => (string) $row->name,
            DB::select("SELECT name FROM {$schema}.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
        ));
    }

    /**
     * @return list<string>
     */
    private function columns(string $schema, string $table): array
    {
        return array_values(array_map(
            fn (\stdClass $row): string => (string) $row->name,
            DB::select("PRAGMA {$schema}.table_info(\"{$table}\")"),
        ));
    }
}
