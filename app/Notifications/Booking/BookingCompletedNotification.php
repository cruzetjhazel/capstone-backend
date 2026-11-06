<?php

namespace App\Notifications\Booking;

use App\Actions\Booking\MarkServiceCompletedAction;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Booking $booking, protected string $completedBy)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking.completed',
            'booking_id' => $this->booking->id,
            'message' => $this->completedBy === MarkServiceCompletedAction::BY_CLIENT
                ? 'The client confirmed this booking as completed.'
                : 'This booking was automatically marked completed after the 48-hour review window ended.',
        ];
    }
}