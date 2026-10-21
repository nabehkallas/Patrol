<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit log entry whenever the model is created, changed or deleted: the old and new
 * values of exactly the attributes that changed. Secrets (password hashes, tokens) and plain
 * timestamps are never recorded. Bulk query-builder updates bypass model events and are not
 * logged, so business records are always changed through their models.
 *
 * @mixin Model
 */
trait Auditable
{
    /** @var list<string> */
    private static array $auditIgnored = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'password_hash', 'created_at', 'updated_at',
    ];

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            AuditLog::record('created', static::auditEntityName(), $model->getKey(), null, self::auditable($model->getAttributes()));
        });

        static::updated(function (Model $model) {
            $changed = self::auditable($model->getChanges());

            if ($changed === []) {
                return;
            }

            // Which record it was (the date it is entered for, ...) even when that didn't
            // change: on both sides with the same value, so the log shows it unchanged.
            $context = self::auditable(array_diff_key(
                array_intersect_key($model->getAttributes(), array_flip(static::auditContext())),
                $changed,
            ));

            $old = array_intersect_key($model->getOriginal(), $changed);
            AuditLog::record('updated', static::auditEntityName(), $model->getKey(), $context + self::auditable($old), $context + $changed);
        });

        static::deleted(function (Model $model) {
            AuditLog::record('deleted', static::auditEntityName(), $model->getKey(), self::auditable($model->getAttributes()), null);
        });
    }

    public static function auditEntityName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Attributes written with every change even when unchanged, so an edit says which record it
     * was: the date it is entered for and the like. A model overrides this.
     *
     * @return list<string>
     */
    public static function auditContext(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function auditable(array $attributes): array
    {
        $values = array_diff_key($attributes, array_flip(self::$auditIgnored));

        // A changed password is still worth recording, just never its hash.
        if (array_key_exists('password', $attributes)) {
            $values['password'] = '[changed]';
        }

        return array_map(
            fn (mixed $value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            $values,
        );
    }
}
