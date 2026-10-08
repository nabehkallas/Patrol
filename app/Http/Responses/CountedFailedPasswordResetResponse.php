<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse;

/**
 * A failed "set new password" attempt. Each wrong or expired token counts against the email; on
 * the 5th failure within the token's lifetime the outstanding token is deleted, so even a
 * correct guess afterwards is useless and a fresh link must be requested.
 */
class CountedFailedPasswordResetResponse extends FailedPasswordResetResponse
{
    public const MAX_FAILURES = 5;

    public function toResponse($request)
    {
        if ($this->status === Password::INVALID_TOKEN) {
            $email = Str::lower(trim((string) $request->input('email')));
            $key = self::key($email);

            RateLimiter::hit($key, (int) config('auth.passwords.users.expire') * 60);

            if (RateLimiter::attempts($key) >= self::MAX_FAILURES) {
                $user = User::where('email', $email)->first();

                if ($user) {
                    Password::broker()->deleteToken($user);
                }

                RateLimiter::clear($key);
            }
        }

        return parent::toResponse($request);
    }

    public static function key(string $email): string
    {
        return 'password-reset-failures:'.sha1(tenant()?->getTenantKey().'|'.$email);
    }
}
