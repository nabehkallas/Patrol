<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
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
        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}
