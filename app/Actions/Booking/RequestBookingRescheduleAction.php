<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use App\Notifications\Booking\BookingRescheduleRequestedNotification;
use App\Services\Photographer\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class RequestBookingRescheduleAction
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected LogActivityAction $activityLogger,
    ) {
    }

    /**
     * Start times this booking could move to on $date. The booking's own current
     * slot is ignored and the photographer's buffer is included, which is exactly
     * what is checked again when the photographer approves.
     *
     * @return array<int, string>
     */
    public function availableSlots(Booking $booking, string $date, string $type = 'standard'): array
    {
        if ($booking->end_time === null) {
            return [];
        }

        $coverage = (int) Carbon::parse($booking->start_time)->diffInMinutes(Carbon::parse($booking->end_time));

        return $this->availabilityService->getAvailableStartTimes(
            $booking->photographer,
            $date,
            $coverage + $booking->bufferMinutes(),
            $booking->id,
            // Weather / venue / agreed postponements may move to a later time the same day.
            $type === 'standard' ? null : 0
        );
    }

    public function execute(Booking $booking, string $eventDate, string $startTime, string $reason, string $type = 'standard'): Booking
    {
        if ($type !== 'standard') {
            // Weather / venue / agreed postponement: allowed close to the event and on Event Day.
            if (! $booking->isEligibleForPostponement()) {
                throw ValidationException::withMessages([
                    'status' => ['A postponement is only possible before the booking reaches Editing, and while no no-show report is pending.'],
                ]);
            }
        } elseif (! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can no longer be rescheduled — the service has already started.'],
            ]);
        }

        if ($booking->hasPendingChangeRequest()) {
            throw ValidationException::withMessages([
                'status' => ['Another request for this booking is still waiting for the photographer\'s answer.'],
            ]);
        }

        if ($type === 'standard' && ! $booking->hasEnoughNoticeForChanges()) {
            throw ValidationException::withMessages([
                'status' => ['Reschedule requests must be made at least '.Booking::CHANGE_NOTICE_DAYS.' days before the event.'],
            ]);
        }

        if ($booking->schedules()->exists()) {
            throw ValidationException::withMessages([
                'status' => ['Bookings with more than one session cannot be rescheduled online. Please message the photographer, or cancel and book again.'],
            ]);
        }

        if ($booking->end_time === null) {
            throw ValidationException::withMessages([
                'status' => ['This booking\'s duration has not been confirmed yet. Please ask the photographer to confirm it before requesting a reschedule.'],
            ]);
        }

        if ($booking->event_date->format('Y-m-d') === $eventDate && substr((string) $booking->start_time, 0, 5) === $startTime) {
            throw ValidationException::withMessages([
                'start_time' => ['That is already the current date and time of this booking.'],
            ]);
        }

        if (! in_array($startTime, $this->availableSlots($booking, $eventDate, $type), true)) {
            throw ValidationException::withMessages([
                'start_time' => ['This date and time is not available for booking.'],
            ]);
        }

        $booking->update([
            'requested_event_date' => $eventDate,
            'requested_start_time' => $startTime,
            'reschedule_reason' => $reason,
            'reschedule_type' => $type,
            'reschedule_requested_at' => now(),
            'reschedule_decision' => null,
            'reschedule_decided_at' => null,
        ]);

        $fresh = $booking->fresh();

        $fresh->photographer->notify(new BookingRescheduleRequestedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.reschedule_requested',
            description: "Requested reschedule to {$eventDate} {$startTime} for booking #{$fresh->id} — reason: {$reason}",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}