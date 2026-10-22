<?php

namespace App\Notifications;

use App\Models\PendingRegistration;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link that confirms a station sign-up's email address. The station is only created when
 * it is opened (StationRegistrationController::verify()).
 */
class ConfirmStationRegistration extends Notification
{
    public function __construct(private PendingRegistration $registration, private string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm your email address for :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $this->registration->owner_name]))
            ->line(__('Thank you for registering :station. Confirm your email address to finish signing up.', ['station' => $this->registration->station_name]))
            ->action(__('Confirm email address'), route('register.verify', ['registration' => $this->registration->id, 'token' => $this->token]))
            ->line(__('This link expires in :count hours. If it expires, you can sign up again.', ['count' => PendingRegistration::LIFETIME_HOURS]))
            ->line(__('If you were not expecting this email, you can ignore it.'));
    }
}
