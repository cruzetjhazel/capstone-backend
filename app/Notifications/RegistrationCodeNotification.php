<?php

namespace App\Notifications;

use App\Services\Auth\RegistrationEmailVerifier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationCodeNotification extends Notification
{
    public function __construct(private string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Bulan verification code')
            ->line('Use this code to verify your email for your photographer application:')
            ->line("**{$this->code}**")
            ->line('It expires in '.RegistrationEmailVerifier::CODE_MINUTES.' minutes. If you did not request it, you can ignore this email.');
    }
}