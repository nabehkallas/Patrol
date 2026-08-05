<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreStationRequest;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class StationController extends Controller
{
    public function index(): Response
    {
        $stations = Tenant::all()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'onboarded' => $tenant->onboarded_at !== null,
                'created_at' => $tenant->created_at,
            ])
            ->sortBy('name')
            ->values();

        return Inertia::render('platform/stations/index', [
            'stations' => $stations,
            'newStationCredentials' => Session::get('new_station_credentials'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/stations/create');
    }

    /**
     * Creates the tenant (this alone provisions its database and runs tenant migrations — see
     * TenancyServiceProvider's TenantCreated event pipeline), seeds just the two roles (not the
     * full DatabaseSeeder — a new station shouldn't get demo fuel types/tanks/prices, that's
     * what the first-run wizard is for), and creates its first admin user with a generated
     * temporary password the super admin relays out of band.
     */
    public function store(StoreStationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $tenant = Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $data['station_name'],
        ]);

        $temporaryPassword = Str::password(16);

        tenancy()->initialize($tenant);

        Role::firstOrCreate(['name' => UserRole::Admin->value]);
        Role::firstOrCreate(['name' => UserRole::Attendant->value]);

        $admin = User::create([
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ]);
        $admin->assignRole(UserRole::Admin->value);

        tenancy()->end();

        TenantUserDirectory::create([
            'email' => $data['admin_email'],
            'tenant_id' => $tenant->id,
        ]);

        Session::flash('new_station_credentials', [
            'station' => $data['station_name'],
            'email' => $data['admin_email'],
            'password' => $temporaryPassword,
        ]);

        return to_route('platform.home');
    }
}
