<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EarningsPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reset the Earnings password'))
            ->line(__('Someone requested a reset of the Earnings tab password for :app.', ['app' => config('app.name')]))
            ->action(__('Reset password'), $this->resetUrl)
            ->line(__('This link expires in 60 minutes.'))
            ->line(__('If you did not request this, no further action is required.'));
    }
}
