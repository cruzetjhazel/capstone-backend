<?php

namespace App\Notifications;

use App\Models\PhotographerApplication;
use Illuminate\Notifications\Notification;

class PhotographerApplicationSubmittedNotification extends Notification
{
    public function __construct(private readonly PhotographerApplication $application)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $name = $this->application->business_name
            ?: ($this->application->user?->name ?? 'A photographer');

        return [
            'type' => 'system',
            'title' => 'New photographer application',
            'description' => "{$name} submitted an application for review. Open Verifications to review it.",
            'application_id' => $this->application->id,
        ];
    }
}