<?php

namespace App\Notifications;

use App\Models\SuspensionAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SuspensionAppealSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(protected SuspensionAppeal $appeal)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $name = $this->appeal->user->name ?? 'A user';

        return [
            'type' => 'system',
            'title' => 'Suspension Appeal Submitted',
            'description' => "{$name} appealed their account suspension. Review it on the Suspension Appeals page.",
            'booking_id' => null,
            'appeal_id' => $this->appeal->id,
            'action' => null,
        ];
    }
}