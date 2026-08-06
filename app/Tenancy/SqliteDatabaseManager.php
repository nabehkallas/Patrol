<?php

namespace App\Tenancy;

use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Same behavior as stancl/tenancy's stock SQLiteDatabaseManager, except tenant database files
 * are resolved from a configurable directory (tenancy.database.sqlite_path) instead of always
 * being hardcoded to database_path(). In production this points at a persistent volume, so
 * station data survives deploys/restarts instead of living on the container's ephemeral disk.
 */
class SqliteDatabaseManager implements TenantDatabaseManager
{
    protected function path(string $name): string
    {
        $directory = rtrim((string) config('tenancy.database.sqlite_path'), '/');

        return $directory.'/'.$name;
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return file_put_contents($this->path($tenant->database()->getName()), '') !== false;
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        try {
            return unlink($this->path($tenant->database()->getName()));
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function databaseExists(string $name): bool
    {
        return file_exists($this->path($name));
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $this->path($databaseName);

        return $baseConfig;
    }

    public function setConnection(string $connection): void
    {
        //
    }
}
