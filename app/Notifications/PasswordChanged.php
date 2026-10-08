<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice sent whenever an account's password changes -- through the reset link or from
 * the account's own settings -- so an owner learns at once if it wasn't them.
 */
class PasswordChanged extends Notification
{
    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $app = (string) config('app.name');

        return (new MailMessage)
            ->subject(__('Your :app password was changed', ['app' => $app]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The password for your account (:email) was changed on :time.', [
                'email' => $notifiable->email,
                'time' => now()->format('Y-m-d H:i T'),
            ]))
            ->line(__('Every other device signed in to this account has been signed out.'))
            ->line(__('If you did not make this change, reset your password right away and contact your station administrator.'))
            ->action(__('Reset password'), url('/forgot-password'));
    }
}
