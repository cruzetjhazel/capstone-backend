<?php

namespace App\Notifications;

use App\Support\Mask;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the PREVIOUS address once an email change has been completed. */
class EmailChanged extends Notification
{
    public function __construct(private readonly string $newEmail)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account email was changed')
            ->line('The email on your '.config('app.name').' account was changed to '.Mask::email($this->newEmail).'.')
            ->line('From now on, sign in and receive notifications with the new address.')
            ->line('If this was not you, reset your password immediately and contact support.');
    }
}