<?php

namespace App\Http\Controllers;

use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreStationRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StationApproved;
use App\Services\StationProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
            ->filter(fn (Tenant $tenant) => StationStatus::of($tenant) === StationStatus::Active)
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'onboarded' => $tenant->onboarded_at !== null,
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
            ->reject(fn (Tenant $tenant) => StationStatus::of($tenant) === StationStatus::Active)
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
                'active_stations' => count($stations),
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

    public function approve(Tenant $tenant): RedirectResponse
    {
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

    public function reject(Tenant $tenant, StationProvisioner $provisioner): RedirectResponse
    {
        abort_if(StationStatus::of($tenant) === StationStatus::Active, 422, 'Live stations cannot be rejected.');

        $name = $tenant->name;
        $provisioner->discard($tenant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':station rejected and removed.', ['station' => $name])]);

        return to_route('platform.home');
    }
}
