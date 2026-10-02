<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a platform admin a newly registered station has verified its email and needs review. */
class StationAwaitingApproval extends Notification
{
    public function __construct(private Tenant $tenant) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New station registration: '.$this->tenant->name)
            ->line('A new station has registered and confirmed its email address.')
            ->line('Station: '.$this->tenant->name)
            ->line('Owner: '.$this->tenant->getAttribute('owner_name').' <'.$this->tenant->getAttribute('owner_email').'>')
            ->line('Phone: '.$this->tenant->getAttribute('owner_phone'))
            ->action('Review registrations', url('/platform'));
    }
}
