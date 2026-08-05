<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing($this->authenticateUsing(...));
    }

    /**
     * Platform admins are plain `User` rows living in the central database — checked first,
     * while we're still on the central connection (no tenant initialized yet). Otherwise, look
     * up which station this email belongs to (a central-only routing table, not a source of
     * credentials) and switch to that station's own database before checking the password
     * there — the tenant's `users` table stays the source of truth for tenant user credentials.
     */
    private function authenticateUsing(Request $request): ?User
    {
        $email = (string) $request->input(Fortify::username());
        $password = (string) $request->input('password');

        $centralUser = User::where('email', $email)->first();

        if ($centralUser && Hash::check($password, $centralUser->password)) {
            return $centralUser;
        }

        $directoryEntry = TenantUserDirectory::where('email', $email)->first();
        $tenant = $directoryEntry ? Tenant::find($directoryEntry->tenant_id) : null;

        if (! $tenant) {
            return null;
        }

        tenancy()->initialize($tenant);

        $tenantUser = User::where('email', $email)->first();

        if ($tenantUser && Hash::check($password, $tenantUser->password)) {
            session(['tenant_id' => $tenant->getTenantKey()]);

            return $tenantUser;
        }

        tenancy()->end();

        return null;
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
