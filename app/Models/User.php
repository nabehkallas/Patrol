<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use App\Notifications\PasswordChanged;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string $default_entry_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'must_change_password', 'default_entry_date'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    use SerializesDatesInAppTimezone;

    /**
     * Emails the verification link without letting a mail outage break the action that created
     * or changed the account: the failure is logged, and the user can ask for the link again
     * from the "verify your email" screen.
     */
    public function sendVerificationLink(): void
    {
        rescue(fn () => $this->sendEmailVerificationNotification());
    }

    /**
     * Emails the "your password was changed" security notice. A mail failure is logged but never
     * undoes or blocks the change itself.
     */
    public function notifyPasswordChanged(): void
    {
        rescue(fn () => $this->notify(new PasswordChanged));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<InventoryEntry, $this>
     */
    public function inventoryEntries(): HasMany
    {
        return $this->hasMany(InventoryEntry::class, 'recorded_by_id');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin->value);
    }
}
