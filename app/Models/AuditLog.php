<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Support\ClientIp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in a station's append-only audit trail (see the create_audit_logs_table migration,
 * whose triggers reject any UPDATE or DELETE). Written by App\Models\Concerns\Auditable for
 * model changes and by AuditLog::record() for station-wide events.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string $action
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use SerializesDatesInAppTimezone;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Mirror the database triggers in the app, so a mistake fails loudly before reaching SQL.
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    /**
     * Records an event, attributed to the signed-in user (if any) and the request's client IP.
     * Only inside a station: audit logs live in each station's own database.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(string $action, ?string $entityType = null, int|string|null $entityId = null, ?array $old = null, ?array $new = null): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $user = auth()->user();
        $request = app()->runningInConsole() ? null : request();

        static::create([
            'user_id' => $user?->getAuthIdentifier(),
            'user_name' => $user instanceof User ? $user->name : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => is_numeric($entityId) ? (int) $entityId : null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request ? ClientIp::of($request) : null,
            'created_at' => now(),
        ]);
    }
}
