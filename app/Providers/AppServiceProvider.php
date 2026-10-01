<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\FactoryImmutable;
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

        // Carbon values placed directly in page props (not through a model) JSON-encode via
        // Carbon's own serializer -- give them the same local-time-with-offset form models use
        // (see SerializesDatesInAppTimezone), so every date the frontend receives matches.
        FactoryImmutable::getDefaultInstance()->serializeUsing(
            fn (CarbonInterface $date): string => CarbonImmutable::instance($date)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d\TH:i:s.uP'),
        );

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
