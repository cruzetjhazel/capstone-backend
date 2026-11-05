<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationAttemptNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim(config('frontend.url'), '/');

        return (new MailMessage)
            ->subject('Someone tried to register with your email')
            ->line('Someone tried to create a Bulan account with this email address, but an account already exists. No new account was created.')
            ->line('If this was you, you can sign in or reset your password.')
            ->action('Sign in', "{$frontend}/login")
            ->line('If it was not you, you can ignore this email. Your account has not been changed.');
    }
}