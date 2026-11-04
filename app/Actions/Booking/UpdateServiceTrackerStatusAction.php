<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Notifications\Booking\ServiceTrackerUpdatedNotification;
use Illuminate\Validation\ValidationException;

class UpdateServiceTrackerStatusAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    /**
     * The manual forward-transitions this endpoint allows.
     * Upcoming -> Event Day is NOT manual: RunServiceProgressTransitionsAction
     * does it automatically at the scheduled start time (Asia/Manila).
     * Completed is separate, via MarkServiceCompletedAction.
     */
    private const ALLOWED_TRANSITIONS = [
        // upcoming -> event_day is NOT allowed here on purpose: it is automatic.
        // event_day -> editing is NOT allowed here on purpose: it only happens through "Confirm Shoot Completed" ($viaShootConfirmation).
        'editing' => ServiceTrackerStatus::Delivered,
    ];

    public function execute(Booking $booking, ServiceTrackerStatus $status, bool $viaShootConfirmation = false): Booking
    {
        if (! $booking->canManageServiceTracker()) {
            throw ValidationException::withMessages([
                'status' => ['The service tracker is only available for a Confirmed booking.'],
            ]);
        }

        // A pending no-show report freezes every forward step (Event Day, Editing, Delivered).
        if ($booking->hasPendingNoShowReport()) {
            throw ValidationException::withMessages([
                'status' => ['A no-show report on this booking is waiting for admin review. The service tracker cannot move forward until it is resolved.'],
            ]);
        }

        if ($booking->service_status === ServiceTrackerStatus::EventDay
            && $status === ServiceTrackerStatus::Editing
            && ! $viaShootConfirmation) {
            throw ValidationException::withMessages([
                'service_status' => ['Use "Confirm Shoot Completed" to move this booking from Event Day to Editing.'],
            ]);
        }

        if ($booking->service_status === ServiceTrackerStatus::Upcoming && $status === ServiceTrackerStatus::EventDay) {
            throw ValidationException::withMessages([
                'service_status' => ['Event Day starts automatically at the scheduled service start time (Philippine time). It cannot be set manually.'],
            ]);
        }

        $expected = self::ALLOWED_TRANSITIONS[$booking->service_status?->value] ?? null;

        // Only the dedicated confirm-shoot endpoint may take Event Day -> Editing.
        if ($viaShootConfirmation && $booking->service_status === ServiceTrackerStatus::EventDay) {
            $expected = ServiceTrackerStatus::Editing;
        }

        if ($expected === null || $status !== $expected) {
            throw ValidationException::withMessages([
                'service_status' => ['Invalid service tracker transition from the current stage.'],
            ]);
        }

        // Neither Event Day nor "shoot completed" can be set before the scheduled service has started.
        if (in_array($booking->service_status, [ServiceTrackerStatus::Upcoming, ServiceTrackerStatus::EventDay], true)) {
            $startsAt = $booking->serviceStartsAt();

            if ($startsAt !== null && $startsAt->isFuture()) {
                throw ValidationException::withMessages([
                    'service_status' => ['This can only be done once the scheduled service has started ('.$startsAt->format('M j, Y g:i A').').'],
                ]);
            }
        }

        // "Shoot completed" (Event Day -> Editing) is only valid once the WHOLE service is over,
        // including every later day of a multi-day booking.
        if ($viaShootConfirmation && $booking->service_status === ServiceTrackerStatus::EventDay) {
            $finishesAt = $booking->serviceFinishesAt();

            if ($finishesAt->isFuture()) {
                throw ValidationException::withMessages([
                    'service_status' => ['The shoot can only be confirmed as completed after the scheduled service ends ('.$finishesAt->format('M j, Y g:i A').').'],
                ]);
            }
        }

        $booking->service_status = $status;
        $booking->service_status_updated_at = now();

        // Delivered is the final tracker stage, but it does NOT complete the
        // booking on its own — BookingStatus only moves to Completed when the
        // photographer explicitly clicks "Mark Service as Completed"
        // (see MarkServiceCompletedAction). Service tracker and booking
        // status are intentionally separate concepts.
        $booking->save();

        $fresh = $booking->fresh();
        $fresh->client->notify(new ServiceTrackerUpdatedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.service_tracker_updated',
            description: "Updated service tracker for booking #{$fresh->id} to {$status->value}",
        );

        return $fresh;
    }
}