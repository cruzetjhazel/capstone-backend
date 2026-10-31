<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the NEW address of an email change. Same idea as Laravel's VerifyEmail
 * (signed, expiring link, hash of the address), but bound to users.pending_email.
 * Send it with Notification::route('mail', $pendingEmail)->notify(new VerifyNewEmail($user)).
 */
class VerifyNewEmail extends Notification
{
    public function __construct(private readonly User $user)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) Config::get('auth.verification.expire', 60);

        $url = URL::temporarySignedRoute(
            'email-change.verify',
            Carbon::now()->addMinutes($minutes),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1(strtolower((string) $this->user->pending_email)),
            ]
        );

        return (new MailMessage)
            ->subject('Verify your new email address')
            ->line('You asked to use this address for your '.config('app.name').' account.')
            ->action('Verify New Email', $url)
            ->line("Your current email stays active until you confirm. This link expires in {$minutes} minutes.")
            ->line('If you did not request this, ignore this email. Nothing will change.');
    }
}