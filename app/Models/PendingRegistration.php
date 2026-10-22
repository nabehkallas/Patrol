<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A station sign-up whose email isn't confirmed yet: a row in the central database and nothing
 * else (no station, no database). Opening the emailed link turns it into a station awaiting a
 * platform admin's approval; one left unconfirmed expires after LIFETIME_HOURS and is pruned.
 *
 * @property int $id
 * @property string $station_name
 * @property string $owner_name
 * @property string $email
 * @property string $phone
 * @property string $password
 * @property string $token_hash
 * @property string $ip
 * @property Carbon $expires_at
 */
#[Fillable(['station_name', 'owner_name', 'email', 'phone', 'password', 'token_hash', 'ip', 'expires_at'])]
#[Hidden(['password', 'token_hash'])]
class PendingRegistration extends Model
{
    use CentralConnection;
    use MassPrunable;
    use SerializesDatesInAppTimezone;

    public const LIFETIME_HOURS = 48;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * Stores a sign-up and returns it with the plain token for the emailed link (only its hash
     * is kept). $password is hashed here.
     *
     * @param  array{station_name: string, owner_name: string, email: string, phone: string, password: string}  $data
     * @return array{0: self, 1: string}
     */
    public static function start(array $data, string $ip): array
    {
        $token = Str::random(48);

        $registration = self::create([
            ...$data,
            'password' => bcrypt($data['password']),
            'token_hash' => hash('sha256', $token),
            'ip' => $ip,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);

        return [$registration, $token];
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $token));
    }

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
