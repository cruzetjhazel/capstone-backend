<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Notifications\Booking\BookingCompletedNotification;
use Illuminate\Validation\ValidationException;

class MarkServiceCompletedAction
{
    public const BY_PHOTOGRAPHER = 'photographer';
    public const BY_CLIENT = 'client';
    public const BY_SYSTEM = 'system';

    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * Confirmed + service_status=Delivered -> Completed. Three ways in:
     *  - the photographer clicks "Mark Service as Completed" (only after the client's 48-hour window);
     *  - the client confirms the booking themselves (allowed inside the 48-hour window);
     *  - the scheduler completes it once the 48-hour window has ended and nobody acted
     *    (see RunServiceProgressTransitionsAction::autoCompleteDelivered()).
     * Every path needs Delivered and no pending no-show report.
     */
    public function execute(Booking $booking, string $completedBy = self::BY_PHOTOGRAPHER): Booking
    {
        if (! $booking->canCompleteService()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can only be marked Completed once the service tracker has reached Delivered.'],
            ]);
        }

        if ($booking->hasPendingNoShowReport()) {
            throw ValidationException::withMessages([
                'status' => ['A no-show report on this booking is waiting for admin review. It cannot be marked Completed until it is resolved.'],
            ]);
        }

        // The 48-hour window protects the client, so only the client may close the booking inside it.
        if ($completedBy !== self::BY_CLIENT) {
            $blockedUntil = $booking->completionBlockedUntil();

            if ($blockedUntil !== null) {
                throw ValidationException::withMessages([
                    'status' => ['This booking can be marked Completed after '.$blockedUntil->format('M j, Y g:i A').', once the client\'s 48-hour window to report a problem has ended.'],
                ]);
            }
        }

        $booking->status = BookingStatus::Completed;
        $booking->save();

        $fresh = $booking->fresh();

        $this->activityLogger->execute(
            causer: match ($completedBy) {
                self::BY_CLIENT => $fresh->client,
                self::BY_SYSTEM => null,
                default => $fresh->photographer,
            },
            subject: $fresh,
            action: 'booking.completed',
            description: match ($completedBy) {
                self::BY_CLIENT => "Booking #{$fresh->id} confirmed as Completed by the client.",
                self::BY_SYSTEM => "Booking #{$fresh->id} automatically marked Completed after the 48-hour window ended.",
                default => "Booking #{$fresh->id} marked Completed by the photographer.",
            },
        );

        $this->notifyCompletion($fresh, $completedBy);

        return $fresh;
    }

    /** Client confirmation tells the photographer; automatic completion tells both. A failure must not undo the completion. */
    private function notifyCompletion(Booking $booking, string $completedBy): void
    {
        if ($completedBy === self::BY_PHOTOGRAPHER) {
            return;
        }

        try {
            $notification = new BookingCompletedNotification($booking, $completedBy);

            $booking->photographer?->notify($notification);

            if ($completedBy === self::BY_SYSTEM) {
                $booking->client?->notify($notification);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}