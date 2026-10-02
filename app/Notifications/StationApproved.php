<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a station owner their registration was approved and the station is live. */
class StationApproved extends Notification
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
        $station = (string) tenant('name');

        return (new MailMessage)
            ->subject(__('Your station :station is now active', ['station' => $station]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Good news: your registration for :station has been approved and your account is now live.', ['station' => $station]))
            ->line(__('Sign in to set up your station: fuel types, tanks, pumps and prices.'))
            ->action(__('Sign in'), url('/login'))
            ->line(__('Welcome aboard!'));
    }
}
