<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['token', 'created_at'])]
class EarningsPasswordResetToken extends Model
{
    public $timestamps = false;

    protected $table = 'earnings_password_reset_tokens';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Creates a token, stores only its hash, and returns the plain value to embed in the
     * reset-link URL (mirrors how Laravel's own password broker handles reset tokens).
     */
    public static function generate(): string
    {
        static::query()->delete();

        $plain = Str::random(64);

        static::create([
            'token' => hash('sha256', $plain),
            'created_at' => now(),
        ]);

        return $plain;
    }

    public static function verify(string $plain, int $expiresInMinutes = 60): bool
    {
        $record = static::query()->find(hash('sha256', $plain));

        return $record !== null && $record->created_at->greaterThanOrEqualTo(Carbon::now()->subMinutes($expiresInMinutes));
    }

    public static function invalidateAll(): void
    {
        static::query()->delete();
    }
}
