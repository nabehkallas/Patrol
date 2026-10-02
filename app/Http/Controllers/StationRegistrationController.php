<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Enums\StationStatus;
use App\Http\Middleware\RequireActiveStation;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use App\Services\StationProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public sign-up for prospective station owners. Registering creates the station in the
 * PendingVerification state and signs the owner in, so the normal "verify your email" step
 * follows straight away. Confirming the email moves the station to PendingApproval (see
 * MarkStationPendingApproval), and it only goes live once a platform admin approves it.
 */
class StationRegistrationController extends Controller
{
    use PasswordValidationRules;

    public function create(): Response
    {
        return Inertia::render('auth/register-station', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, StationProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'station_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::where('email', $value)->exists() || TenantUserDirectory::where('email', $value)->exists()) {
                        $fail(__('This email is already in use.'));
                    }
                },
            ],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/'],
            'password' => $this->passwordRules(),
        ]);

        [$tenant, $owner] = $provisioner->create(
            $data['station_name'], $data['owner_name'], $data['email'], $data['password'],
            mustChangePassword: false,
            status: StationStatus::PendingVerification,
            tenantAttributes: [
                'owner_name' => $data['owner_name'],
                'owner_email' => $data['email'],
                'owner_phone' => $data['phone'],
            ],
        );

        // Signed in inside the new station, the same way login does it (see
        // FortifyServiceProvider::authenticateUsing), so the verify screen knows who they are.
        $request->session()->regenerate();
        Auth::login($owner);
        session(['tenant_id' => $tenant->getTenantKey()]);

        $owner->sendVerificationLink();

        return to_route('verification.notice');
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
}
