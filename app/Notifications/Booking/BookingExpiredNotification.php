<?php

namespace App\Notifications\Booking;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(protected Booking $booking, protected string $cause = 'unpaid')
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking.expired',
            'booking_id' => $this->booking->id,
            'message' => $this->cause === 'no_response'
                ? 'Your booking request expired because the photographer did not respond in time.'
                : 'Your booking expired because the reservation payment was not completed in time.',
        ];
    }
}