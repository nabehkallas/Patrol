<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

#[Fillable(['password_hash'])]
class EarningsPassword extends Model
{
    use Auditable;
    use SerializesDatesInAppTimezone;

    protected $table = 'earnings_password';

    public static function isSet(): bool
    {
        return static::query()->whereNotNull('password_hash')->exists();
    }

    public static function check(string $password): bool
    {
        $record = static::query()->first();

        return $record && $record->password_hash && Hash::check($password, $record->password_hash);
    }

    public static function set(string $password): void
    {
        $record = static::query()->first() ?? new self;
        $record->password_hash = Hash::make($password);
        $record->save();
    }
}
