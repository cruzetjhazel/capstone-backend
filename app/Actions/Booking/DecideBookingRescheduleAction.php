<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Notifications\Booking\BookingRescheduleDecidedNotification;
use App\Services\Photographer\AvailabilityService;
use App\Services\Photographer\Booking\BookingDeadlineService;
use App\Services\Photographer\Booking\SlotConflictService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class DecideBookingRescheduleAction
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected LogActivityAction $activityLogger,
        protected BookingDeadlineService $deadlines,
        protected SlotConflictService $conflicts,
    ) {
    }

    public function execute(Booking $booking, CancellationDecision $decision): Booking
    {
        if (! $booking->hasPendingRescheduleRequest()) {
            throw ValidationException::withMessages([
                'status' => ['There is no pending reschedule request for this booking.'],
            ]);
        }

        if ($decision === CancellationDecision::Approved) {
            $isPostponement = ($booking->reschedule_type ?? 'standard') !== 'standard';
            $eligible = $isPostponement
                ? $booking->isEligibleForPostponement()
                : $booking->isEligibleForCancellationRequest();

            if (! $eligible) {
                throw ValidationException::withMessages([
                    'status' => ['This booking can no longer be rescheduled — it is no longer active or the service has already started.'],
                ]);
            }

            // Remember the slot being replaced (written to the reschedule history below).
            $originalDate = $booking->event_date->format('Y-m-d');
            $originalStart = substr((string) $booking->start_time, 0, 5);
            $originalEnd = $booking->end_time ? substr((string) $booking->end_time, 0, 5) : null;

            $eventDate = $booking->requested_event_date->format('Y-m-d');
            $startTime = substr((string) $booking->requested_start_time, 0, 5);
            $coverage = (int) Carbon::parse($booking->start_time)->diffInMinutes(Carbon::parse($booking->end_time));

            // Re-check at approval time (someone else may have paid for that slot meanwhile).
            // The booking's own current slot is ignored.
            $slots = $this->availabilityService->getAvailableStartTimes(
                $booking->photographer,
                $eventDate,
                $coverage + $booking->bufferMinutes(),
                $booking->id
            );

            if (! in_array($startTime, $slots, true)) {
                throw ValidationException::withMessages([
                    'start_time' => ['That slot is no longer available — ask the client to pick another time.'],
                ]);
            }

            // The booking keeps its current status (a Pending request stays Pending).
            $booking->event_date = $eventDate;
            $booking->start_time = $startTime;
            $booking->end_time = Carbon::parse($startTime)->addMinutes($coverage)->format('H:i');
            $booking->rescheduled_at = now();

            // An approved postponement puts the booking back to Upcoming on the new date
            // (there is no "Rescheduled" status). The normal lifecycle then starts again.
            if ($isPostponement) {
                $booking->service_status = ServiceTrackerStatus::Upcoming;
                $booking->service_status_updated_at = now();
            }

            // Accepted but not paid yet: the payment deadline depends on the new date.
            if ($booking->status === BookingStatus::Confirmed && $booking->payment_status === BookingPaymentStatus::Pending) {
                $booking->hold_expires_at = $this->deadlines->paymentDeadline($booking);
            }
        }

        $booking->reschedule_decision = $decision;
        $booking->reschedule_decided_at = now();
        $booking->save();

        $fresh = $booking->fresh();

        // Reschedule history: original slot, new slot, reason, who requested, who approved.
        if ($decision === CancellationDecision::Approved) {
            $fresh->reschedules()->create([
                'type' => $booking->reschedule_type ?: 'standard',
                'original_event_date' => $originalDate,
                'original_start_time' => $originalStart,
                'original_end_time' => $originalEnd,
                'new_event_date' => $fresh->event_date->format('Y-m-d'),
                'new_start_time' => substr((string) $fresh->start_time, 0, 5),
                'new_end_time' => $fresh->end_time ? substr((string) $fresh->end_time, 0, 5) : null,
                'reason' => $booking->reschedule_reason,
                'requested_by' => $booking->client_id,
                'approved_by' => $booking->photographer_id,
                'requested_at' => $booking->reschedule_requested_at,
                'decided_at' => $booking->reschedule_decided_at,
            ]);
        }

        // A paid booking now owns its new slot: other accepted bookings for that time go to the waitlist.
        if ($decision === CancellationDecision::Approved && $fresh->isPaymentSettled()) {
            $this->conflicts->waitlistConflictingBookings($fresh);
        }

        $fresh->client->notify(new BookingRescheduleDecidedNotification($fresh, $decision));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: $decision === CancellationDecision::Approved ? 'booking.reschedule_approved' : 'booking.reschedule_rejected',
            description: $decision === CancellationDecision::Approved
                ? "Approved reschedule for booking #{$fresh->id}: {$originalDate} {$originalStart} → {$fresh->event_date->format('Y-m-d')} ".substr((string) $fresh->start_time, 0, 5)
                : "Declined reschedule request for booking #{$fresh->id}",
        );

        return $fresh;
    }
}