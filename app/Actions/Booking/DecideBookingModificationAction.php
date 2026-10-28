<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\CancellationDecision;
use App\Models\Booking;
use App\Notifications\Booking\BookingModificationDecidedNotification;
use Illuminate\Validation\ValidationException;

class DecideBookingModificationAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, CancellationDecision $decision): Booking
    {
        if (! $booking->hasPendingModificationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['There is no pending modification request for this booking.'],
            ]);
        }

        if ($decision === CancellationDecision::Approved) {
            if (! $booking->isEligibleForCancellationRequest()) {
                throw ValidationException::withMessages([
                    'status' => ['This booking can no longer be modified — it is no longer active or the service has already started.'],
                ]);
            }

            // Only the whitelisted detail fields can ever be applied.
            $changes = array_intersect_key(
                $booking->modification_changes ?? [],
                array_flip(RequestBookingModificationAction::EDITABLE_FIELDS)
            );

            if ($changes !== []) {
                $booking->forceFill($changes);
            }
        }

        $booking->modification_decision = $decision;
        $booking->modification_decided_at = now();
        $booking->save();

        $fresh = $booking->fresh();

        $fresh->client->notify(new BookingModificationDecidedNotification($fresh, $decision));

        $this->activityLogger->execute(
            causer: $fresh->photographer,
            subject: $fresh,
            action: $decision === CancellationDecision::Approved ? 'booking.modification_approved' : 'booking.modification_rejected',
            description: $decision === CancellationDecision::Approved
                ? "Approved the detail changes for booking #{$fresh->id}"
                : "Declined the detail changes for booking #{$fresh->id}",
            metadata: ['changes' => $fresh->modification_changes, 'reason' => $fresh->modification_reason],
        );

        return $fresh;
    }
}