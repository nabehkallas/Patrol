<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/** Emails the security notice after a password is reset through the emailed link. */
class SendPasswordChangedNotice
{
    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $event->user->notifyPasswordChanged();
        }
    }
}
