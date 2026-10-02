<?php

namespace App\Services;

use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates a station and its first admin. Shared by the platform admin's "new station" form
 * and public self-registration so both set a station up identically. Creating the tenant
 * provisions its database and runs tenant migrations (see TenancyServiceProvider).
 */
class StationProvisioner
{
    /**
     * Leaves tenancy initialized for the new station, so the caller can log the admin in or
     * send them email in that station's context. Callers that don't need it call tenancy()->end().
     *
     * @param  array<string, mixed>  $tenantAttributes  extra station data (status, owner phone, ...)
     * @return array{0: Tenant, 1: User}
     */
    public function create(
        string $stationName,
        string $adminName,
        string $adminEmail,
        string $password,
        bool $mustChangePassword,
        StationStatus $status,
        array $tenantAttributes = [],
    ): array {
        $tenant = Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $stationName,
            'status' => $status->value,
            ...$tenantAttributes,
        ]);

        TenantUserDirectory::create(['email' => $adminEmail, 'tenant_id' => $tenant->id]);

        tenancy()->initialize($tenant);

        Role::firstOrCreate(['name' => UserRole::Admin->value]);
        Role::firstOrCreate(['name' => UserRole::Attendant->value]);

        $admin = User::create([
            'name' => $adminName,
            'email' => $adminEmail,
            'password' => $password,
            'must_change_password' => $mustChangePassword,
        ]);
        $admin->assignRole(UserRole::Admin->value);

        return [$tenant, $admin];
    }

    /**
     * Removes a station that never went live: its users' login directory rows, then the tenant
     * itself (whose deletion also deletes its database).
     */
    public function discard(Tenant $tenant): void
    {
        TenantUserDirectory::where('tenant_id', $tenant->id)->delete();
        $tenant->delete();
    }
}
