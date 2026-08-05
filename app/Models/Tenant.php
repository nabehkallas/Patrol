<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property string $name Station name — stored in the virtual `data` column, not a real column.
 * @property Carbon|null $onboarded_at Set once the first-run wizard completes.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    protected function casts(): array
    {
        return [
            'onboarded_at' => 'datetime',
        ];
    }
}
