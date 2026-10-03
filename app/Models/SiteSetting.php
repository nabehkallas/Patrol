<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A platform-wide setting stored as JSON in the central database.
 *
 * @property string $key
 * @property string $value
 */
#[Fillable(['key', 'value'])]
class SiteSetting extends Model
{
    use CentralConnection;
    use SerializesDatesInAppTimezone;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<mixed>|null
     */
    public static function getJson(string $key): ?array
    {
        $value = static::query()->find($key)?->value;
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed>  $value
     */
    public static function putJson(string $key, array $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
