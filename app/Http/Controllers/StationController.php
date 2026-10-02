<?php

namespace App\Http\Controllers;

use App\Enums\StationStatus;
use App\Http\Requests\StoreStationRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StationApproved;
use App\Services\StationProvisioner;
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

        $stations = collect($tenants)
            ->filter(fn (Tenant $tenant) => StationStatus::of($tenant) === StationStatus::Active)
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'onboarded' => $tenant->onboarded_at !== null,
                'created_at' => $tenant->created_at,
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
            ->sortBy([['status', 'desc'], ['created_at', 'asc']])
            ->values()
            ->all();

        return Inertia::render('platform/stations/index', [
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

    public function approve(Tenant $tenant): RedirectResponse
    {
        abort_unless(StationStatus::of($tenant) === StationStatus::PendingApproval, 422, 'This station is not awaiting approval.');

        $tenant->update(['status' => StationStatus::Active->value]);

        // The owner is the station's first admin; tell them it's live, in their station's context.
        $tenant->run(function () {
            $owner = User::orderBy('id')->first();
            rescue(fn () => $owner?->notify(new StationApproved));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tenant->name} approved. The owner has been emailed."]);

        return to_route('platform.home');
    }

    public function reject(Tenant $tenant, StationProvisioner $provisioner): RedirectResponse
    {
        abort_if(StationStatus::of($tenant) === StationStatus::Active, 422, 'Live stations cannot be rejected.');

        $name = $tenant->name;
        $provisioner->discard($tenant);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$name} rejected and removed."]);

        return to_route('platform.home');
    }
}
