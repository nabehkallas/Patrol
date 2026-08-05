<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central-only lookup: which tenant a login email belongs to, so the login flow knows which
 * tenant database to switch to before checking the password there. Not the source of truth for
 * anything else about the user. Always queries the central connection regardless of whichever
 * tenant is currently active (same mechanism stancl/tenancy's own Tenant/Domain models use).
 */
#[Fillable(['email', 'tenant_id'])]
class TenantUserDirectory extends Model
{
    use CentralConnection;

    protected $table = 'tenant_user_directory';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
