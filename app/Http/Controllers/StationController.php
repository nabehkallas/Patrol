<?php

namespace App\Http\Controllers;

use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreStationRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StationApproved;
use App\Services\StationProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()->cursor()->all();

        // One pass into each station's own database for its head count and first admin (the
        // contact shown on its card). Stations are few, so this stays cheap.
        $people = [];
        foreach ($tenants as $tenant) {
            $people[$tenant->id] = $tenant->run(function () {
                $admin = User::role(UserRole::Admin->value)->orderBy('id')->first(['name', 'email']);

                return ['users' => User::count(), 'admin_name' => $admin?->name, 'admin_email' => $admin?->email];
            });
        }

        $stations = collect($tenants)
            ->filter(fn (Tenant $tenant) => in_array(StationStatus::of($tenant), [StationStatus::Active, StationStatus::Suspended], true))
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'onboarded' => $tenant->onboarded_at !== null,
                'suspended' => StationStatus::of($tenant) === StationStatus::Suspended,
                'created_at' => $tenant->created_at,
                ...$people[$tenant->id],
                'owner_phone' => $tenant->getAttribute('owner_phone'),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        // Self-registered stations not live yet: awaiting approval first (actionable), then
        // those whose owner hasn't confirmed their email yet (shown for context only).
        $registrations = collect($tenants)
            ->filter(fn (Tenant $tenant) => in_array(StationStatus::of($tenant), [StationStatus::PendingVerification, StationStatus::PendingApproval], true))
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => StationStatus::of($tenant)->value,
                'owner_name' => $tenant->getAttribute('owner_name'),
                'owner_email' => $tenant->getAttribute('owner_email'),
                'owner_phone' => $tenant->getAttribute('owner_phone'),
                'created_at' => $tenant->created_at,
            ])
            ->sortBy([
                fn (array $a, array $b) => ($b['status'] === StationStatus::PendingApproval->value) <=> ($a['status'] === StationStatus::PendingApproval->value),
                ['created_at', 'asc'],
            ])
            ->values()
            ->all();

        return Inertia::render('platform/stations/index', [
            'stats' => [
                'active_stations' => collect($stations)->where('suspended', false)->count(),
                'suspended_stations' => collect($stations)->where('suspended', true)->count(),
                'pending_approval' => collect($registrations)->where('status', StationStatus::PendingApproval->value)->count(),
                'total_users' => array_sum(array_column($stations, 'users')),
            ],
            'stations' => $stations,
            'registrations' => $registrations,
            'newStationCredentials' => Session::get('new_station_credentials'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/stations/create');
    }

    /**
     * A station created directly by the platform admin: live straight away, with a generated
     * temporary password the admin relays out of band (changed on first login).
     */
    public function store(StoreStationRequest $request, StationProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validated();
        $temporaryPassword = Str::password(16);

        [, $admin] = $provisioner->create(
            $data['station_name'], $data['admin_name'], $data['admin_email'],
            $temporaryPassword, mustChangePassword: true, status: StationStatus::Active,
        );
        $admin->sendVerificationLink();

        tenancy()->end();

        Session::flash('new_station_credentials', [
            'station' => $data['station_name'],
            'email' => $data['admin_email'],
            'password' => $temporaryPassword,
        ]);

        return to_route('platform.home');
    }

    /**
     * The accounts of one station, for the users dialog on its card. Read from that station's
     * own database; loaded only when the dialog is opened.
     */
    public function users(Tenant $tenant): JsonResponse
    {
        $users = $tenant->run(fn () => User::with('roles')->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'verified' => $user->email_verified_at !== null,
            ])
            ->all());

        return response()->json(['users' => $users]);
    }

    public function approve(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->confirmPassword($request);
        abort_unless(StationStatus::of($tenant) === StationStatus::PendingApproval, 422, 'This station is not awaiting approval.');

        $tenant->update(['status' => StationStatus::Active->value]);

        // The owner is the station's first admin; tell them it's live, in their station's context.
        $tenant->run(function () {
            $owner = User::orderBy('id')->first();
            rescue(fn () => $owner?->notify(new StationApproved));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':station approved. The owner has been emailed.', ['station' => $tenant->name])]);

        return to_route('platform.home');
    }

    public function reject(Request $request, Tenant $tenant, StationProvisioner $provisioner): RedirectResponse
    {
        $this->confirmPassword($request);
        abort_unless(in_array(StationStatus::of($tenant), [StationStatus::PendingVerification, StationStatus::PendingApproval], true), 422, 'Only pending registrations can be rejected.');

        $name = $tenant->name;
        $provisioner->discard($tenant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':station rejected and removed.', ['station' => $name])]);

        return to_route('platform.home');
    }

    /** Freezes a live station: its users can't sign in, and anyone signed in is signed out. */
    /**
     * Offline-safe recovery for a station admin who can't use the email reset link: after the
     * platform admin confirms their own password, the station admin gets a one-time temporary
     * password (shown once, like a new station's) that must be changed at the next sign-in.
     * Their other sessions end, they're emailed the security notice, and it's audit-logged.
     */
    public function resetAdminPassword(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->confirmPassword($request);

        $temporaryPassword = Str::password(16);

        $admin = $tenant->run(function () use ($temporaryPassword) {
            $admin = User::role(UserRole::Admin->value)->orderBy('id')->first();
            abort_unless($admin !== null, 422, 'This station has no admin account.');

            $admin->forceFill(['password' => $temporaryPassword, 'must_change_password' => true])->save();
            AuditLog::record('password.admin_override', 'User', $admin->id);
            $admin->notifyPasswordChanged();

            return $admin;
        });

        Session::flash('new_station_credentials', [
            'station' => $tenant->name,
            'email' => $admin->email,
            'password' => $temporaryPassword,
            'kind' => 'reset',
        ]);

        return to_route('platform.home');
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->confirmPassword($request);
        abort_unless(StationStatus::of($tenant) === StationStatus::Active, 422, 'Only active stations can be suspended.');

        $tenant->update(['status' => StationStatus::Suspended->value, 'suspended_at' => now()->toIso8601String()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':station suspended. Its users can no longer sign in.', ['station' => $tenant->name])]);

        return to_route('platform.home');
    }

    public function reactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->confirmPassword($request);
        abort_unless(StationStatus::of($tenant) === StationStatus::Suspended, 422, 'Only suspended stations can be reactivated.');

        $tenant->update(['status' => StationStatus::Active->value, 'suspended_at' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':station reactivated. Its users can sign in again.', ['station' => $tenant->name])]);

        return to_route('platform.home');
    }

    /** Permanently deletes a live or suspended station: its database and every login for it. */
    public function destroy(Request $request, Tenant $tenant, StationProvisioner $provisioner): RedirectResponse
    {
        $this->confirmPassword($request);
        abort_unless(in_array(StationStatus::of($tenant), [StationStatus::Active, StationStatus::Suspended], true), 422, 'Pending registrations are removed with Reject.');

        $name = $tenant->name;
        $provisioner->discard($tenant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':station and all its data were deleted.', ['station' => $name])]);

        return to_route('platform.home');
    }

    /**
     * Every action that changes or removes a station needs the platform admin's current
     * password, entered in the confirmation dialog. Errors go to the 'confirm' bag so the
     * dialog shows them.
     */
    private function confirmPassword(Request $request): void
    {
        $request->validateWithBag('confirm', [
            'current_password' => ['required', 'string', 'current_password'],
        ]);
    }
}
