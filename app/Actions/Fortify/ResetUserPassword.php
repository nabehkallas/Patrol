<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // The user just chose this password themselves through their own inbox, so it is no
        // longer an admin-issued temporary one, and reaching the link proves they own the email.
        $attributes = [
            'password' => $input['password'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ];

        // Only station users have a forced temporary password; platform admins live in the
        // central database, whose users table has no such column.
        if (Schema::connection($user->getConnectionName())->hasColumn('users', 'must_change_password')) {
            $attributes['must_change_password'] = false;
        }

        $user->forceFill($attributes)->save();
    }
}
