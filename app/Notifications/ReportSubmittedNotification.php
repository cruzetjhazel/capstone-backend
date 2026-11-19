<?php

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReportSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Report $report)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $name = $this->report->reporter->name ?? 'A user';
        $severity = $this->report->severity?->value ?? 'medium';

        return [
            'type' => 'system',
            'title' => 'New Report Submitted',
            'description' => "{$name} submitted report {$this->report->referenceCode()} ({$severity} severity): {$this->report->reason}",
            'booking_id' => null,
            'report_id' => $this->report->id,
            'action' => null,
        ];
    }
}