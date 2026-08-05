<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTestDatabase();
    }

    /**
     * Tests run against one flat database (not real multi-tenancy), so the tenant-only
     * migrations (tanks, transactions, roles, etc.) need to merge into that same database
     * alongside the central ones — otherwise business-logic tests would be missing almost every
     * table. `loadMigrationsFrom` registers an additional path the migrator always includes,
     * regardless of a command's own `--path` option, which a `TestCase::migrateFreshUsing()`
     * override can't reliably achieve (RefreshDatabase's own trait method takes precedence over
     * an inherited parent-class override in PHP's method resolution).
     */
    protected function configureTestDatabase(): void
    {
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/tenant'));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
