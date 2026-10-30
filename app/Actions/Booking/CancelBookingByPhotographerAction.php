<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\CancellationDecision;
use App\Models\Booking;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Validation\ValidationException;

/**
 * The photographer ends a CONFIRMED booking themselves (e.g. an emergency).
 * To turn down a brand-new request they use "Decline" (RejectBookingAction).
 */
class CancelBookingByPhotographerAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
    ) {
    }

    public function execute(Booking $booking, string $reason): Booking
    {
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Waitlisted], true) || ! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['Only a confirmed booking that has not started yet can be cancelled. To turn down a new request, decline it instead.'],
            ]);
        }

        // Did this booking hold a paid slot? Then cancelling it releases that slot.
        $heldSlot = $booking->status === BookingStatus::Confirmed && $booking->isPaymentSettled();

        $updates = [
            'status' => BookingStatus::Cancelled,
            'cancellation_reason' => $reason,
            'cancelled_by' => 'photographer',
            'hold_expires_at' => null,
        ];

        // Close any request that was still waiting for an answer, so nothing stays "pending".
        if ($booking->hasPendingCancellationRequest()) {
            $updates['cancellation_decision'] = CancellationDecision::Approved;
            $updates['cancellation_decided_at'] = now();
        }
        if ($booking->hasPendingRescheduleRequest()) {
            $updates['reschedule_decision'] = CancellationDecision::Rejected;
            $updates['reschedule_decided_at'] = now();
        }
        if ($booking->hasPendingModificationRequest()) {
            $updates['modification_decision'] = CancellationDecision::Rejected;
            $updates['modification_decided_at'] = now();
        }

        $booking->update($updates);

        $fresh = $booking->fresh();

        $fresh->client?->notify(new BookingCancelledNotification($fresh));

       app(OfferReleasedSlotAction::class)->passOnOffer($fresh);
   \App\Models\BookingBudgetRequest::closeOpenFor($fresh, \App\Models\BookingBudgetRequest::WITHDRAWN);

        if ($heldSlot) {
            app(OfferReleasedSlotAction::class)->execute($fresh);
        }

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: 'booking.cancelled_by_photographer',
            description: "Cancelled booking #{$fresh->id} (photographer)",
            metadata: ['reason' => $reason],
        );

        return $fresh;
    }
}