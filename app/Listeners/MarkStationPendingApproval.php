<?php

namespace App\Listeners;

use App\Enums\StationStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StationAwaitingApproval;
use Illuminate\Auth\Events\Verified;

/**
 * When the owner of a self-registered station confirms their email, the station moves to
 * "awaiting approval" and the platform admins are emailed so they know to review it.
 */
class MarkStationPendingApproval
{
    public function handle(Verified $event): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || StationStatus::of($tenant) !== StationStatus::PendingVerification) {
            return;
        }

        $tenant->update(['status' => StationStatus::PendingApproval->value]);

        // Platform admins live in the central database.
        tenancy()->central(function () use ($tenant) {
            foreach (User::all() as $admin) {
                rescue(fn () => $admin->notify(new StationAwaitingApproval($tenant)));
            }
        });
    }
}
