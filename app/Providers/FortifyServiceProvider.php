<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Enums\StationStatus;
use App\Http\Responses\CountedFailedPasswordResetResponse;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Forgot-password answers the same whether or not the email has an account, and wrong
        // reset tokens are counted so a link dies after five failed attempts.
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetResponse::class, CountedFailedPasswordResetResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureEmails();
    }

    /**
     * The verification and reset emails, worded for station accounts (which an admin creates,
     * rather than people signing themselves up) and translated through lang/ar.json. Both
     * links are signed and expire: verification after auth.verification.expire minutes, reset
     * after auth.passwords.users.expire minutes.
     */
    private function configureEmails(): void
    {
        VerifyEmail::toMailUsing(fn (User $notifiable, string $url) => (new MailMessage)
            ->subject(__('Confirm your email address for :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An account was created for you on :app. Please confirm this is your email address to start using it.', ['app' => config('app.name')]))
            ->action(__('Confirm email address'), $url)
            ->line(__('This link expires in :count minutes. You can ask for a new one from the sign-in screen.', ['count' => config('auth.verification.expire', 60)]))
            ->line(__('If you were not expecting this email, you can ignore it.')));

        ResetPassword::toMailUsing(fn (User $notifiable, string $token) => (new MailMessage)
            ->subject(__('Reset your :app password', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('We received a request to reset the password for your account.'))
            ->action(__('Set a new password'), url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false)))
            ->line(__('This link expires in :count minutes and can be used once.', ['count' => config('auth.passwords.users.expire', 60)]))
            ->line(__('If you did not ask to reset your password, you can ignore this email. Your password will not change.')));
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
            // Correct credentials, but the station's subscription is frozen by the platform admin.
            if (StationStatus::of($tenant) === StationStatus::Suspended) {
                tenancy()->end();

                throw ValidationException::withMessages([
                    Fortify::username() => __('Subscription expired. Please contact support.'),
                ]);
            }

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

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
            'email' => $request->user()?->email,
        ]));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.ClientIp::of($request));

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Every page and action: 100 requests a minute per signed-in user (per station), or per
        // IP for guests -- generous for people, a ceiling for scripts and scrapers.
        RateLimiter::for('web-requests', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(100)->by($user
                ? 'user:'.(tenant()?->getTenantKey() ?? 'central').':'.$user->getAuthIdentifier()
                : 'ip:'.ClientIp::of($request));
        });
    }
}
