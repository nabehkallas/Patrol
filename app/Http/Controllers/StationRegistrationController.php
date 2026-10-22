<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Enums\StationStatus;
use App\Http\Middleware\RequireActiveStation;
use App\Models\PendingRegistration;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use App\Notifications\ConfirmStationRegistration;
use App\Notifications\StationAwaitingApproval;
use App\Services\StationProvisioner;
use App\Services\Turnstile;
use App\Support\ClientIp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Public sign-up for prospective station owners. Signing up (behind a captcha) stores only a
 * PendingRegistration and emails a confirmation link; no station or database exists until
 * that link is opened. Then the station is created awaiting a platform admin's approval, with
 * its owner's email already confirmed, and only goes live once approved. Unconfirmed sign-ups
 * expire after PendingRegistration::LIFETIME_HOURS and are pruned (routes/console.php).
 *
 * Caps keep a flood of sign-ups from filling the disk: open sign-ups per IP and overall, and
 * stations waiting for approval.
 */
class StationRegistrationController extends Controller
{
    use PasswordValidationRules;

    public const MAX_OPEN_PER_IP = 3;

    public const MAX_OPEN_TOTAL = 100;

    public const MAX_AWAITING_APPROVAL = 30;

    public function create(Turnstile $turnstile): Response
    {
        return Inertia::render('auth/register-station', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'turnstileSiteKey' => $turnstile->siteKey(),
        ]);
    }

    public function store(Request $request, Turnstile $turnstile): RedirectResponse
    {
        $ip = ClientIp::of($request);

        // First, so a script can't use the form to probe which emails have accounts.
        if (! $turnstile->passes($request->string('cf-turnstile-response')->toString(), $ip)) {
            throw ValidationException::withMessages(['captcha' => __('Please confirm you are not a robot and try again.')]);
        }

        $data = $request->validate([
            'station_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::where('email', $value)->exists() || TenantUserDirectory::where('email', $value)->exists()) {
                        $fail(__('This email is already in use.'));
                    } elseif (PendingRegistration::open()->where('email', $value)->exists()) {
                        $fail(__('A confirmation link was already sent to this email. Open it to finish signing up, or sign up again after it expires.'));
                    }
                },
            ],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/'],
            'password' => $this->passwordRules(),
        ]);

        $this->assertRoomForSignUp($ip);

        // An expired, not yet pruned sign-up for the same email makes way for this one.
        PendingRegistration::where('email', $data['email'])->delete();

        [$registration, $token] = PendingRegistration::start([
            'station_name' => $data['station_name'],
            'owner_name' => $data['owner_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
        ], $ip);

        try {
            Notification::route('mail', $registration->email)->notify(new ConfirmStationRegistration($registration, $token));
        } catch (Throwable $e) {
            // Without the email the sign-up could never be confirmed: let them try again.
            $registration->delete();
            Log::error('Sign-up confirmation email failed: '.$e->getMessage());

            throw ValidationException::withMessages(['email' => __('We could not send the confirmation email. Please try again later.')]);
        }

        return to_route('register.sent')->with('registration_email', $registration->email);
    }

    /** "Check your email": shown after signing up. */
    public function sent(Request $request): Response
    {
        return Inertia::render('auth/register-sent', [
            'email' => $request->session()->get('registration_email'),
            'hours' => PendingRegistration::LIFETIME_HOURS,
        ]);
    }

    /**
     * The emailed link: creates the station, awaiting approval, with the owner's email already
     * confirmed, and signs the owner in.
     */
    public function verify(Request $request, PendingRegistration $registration, string $token, StationProvisioner $provisioner): RedirectResponse
    {
        abort_unless($registration->matches($token), 404);

        if ($registration->expired()) {
            $registration->delete();
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This confirmation link has expired. Please sign up again.')]);

            return to_route('register');
        }

        if (User::where('email', $registration->email)->exists() || TenantUserDirectory::where('email', $registration->email)->exists()) {
            $registration->delete();

            return to_route('login')->withErrors(['email' => __('This email is already in use.')]);
        }

        if ($this->awaitingApproval() >= self::MAX_AWAITING_APPROVAL) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Sign-ups are paused for now. Please try again later.')]);

            return to_route('register');
        }

        // The password was hashed when the sign-up was stored; User's 'hashed' cast keeps a hash as is.
        [$tenant, $owner] = $provisioner->create(
            $registration->station_name, $registration->owner_name, $registration->email, $registration->password,
            mustChangePassword: false,
            status: StationStatus::PendingApproval,
            tenantAttributes: [
                'owner_name' => $registration->owner_name,
                'owner_email' => $registration->email,
                'owner_phone' => $registration->phone,
            ],
        );
        $owner->markEmailAsVerified();
        $registration->delete();

        StationAwaitingApproval::sendToPlatformAdmins($tenant);

        // Signed in inside the new station, the same way login does it (see
        // FortifyServiceProvider::authenticateUsing).
        $request->session()->regenerate();
        Auth::login($owner);
        session(['tenant_id' => $tenant->getTenantKey()]);

        return to_route('registration.pending');
    }

    /** What a verified owner sees until a platform admin approves the station. */
    public function pending(Request $request): Response|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && StationStatus::of($tenant) === StationStatus::Suspended) {
            return RequireActiveStation::signOutSuspended($request);
        }

        if (! $tenant instanceof Tenant || StationStatus::of($tenant) === StationStatus::Active) {
            return redirect('/');
        }

        return Inertia::render('auth/registration-pending', [
            'stationName' => $tenant->name,
        ]);
    }

    private function assertRoomForSignUp(string $ip): void
    {
        if (PendingRegistration::open()->where('ip', $ip)->count() >= self::MAX_OPEN_PER_IP) {
            throw ValidationException::withMessages(['email' => __('Too many sign-ups from this network. Confirm the ones already sent, or try again later.')]);
        }

        if (PendingRegistration::open()->count() >= self::MAX_OPEN_TOTAL || $this->awaitingApproval() >= self::MAX_AWAITING_APPROVAL) {
            throw ValidationException::withMessages(['email' => __('Sign-ups are paused for now. Please try again later.')]);
        }
    }

    private function awaitingApproval(): int
    {
        return Tenant::all()->filter(fn ($tenant) => $tenant instanceof Tenant && in_array(
            StationStatus::of($tenant), [StationStatus::PendingVerification, StationStatus::PendingApproval], true,
        ))->count();
    }
}
