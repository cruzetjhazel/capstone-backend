<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Models\Booking;
use App\Notifications\Booking\BookingModificationRequestedNotification;
use Illuminate\Validation\ValidationException;

/**
 * "Modify" = ask to update event DETAILS only. Anything that changes the date/time
 * (Reschedule), the hours (Extension) or the price/package (Cancel and rebook)
 * has its own flow, so it is deliberately not editable here.
 */
class RequestBookingModificationAction
{
    public const EDITABLE_FIELDS = ['guest_count', 'event_address', 'special_requests'];

    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, array $changes, string $reason): Booking
    {
        if (! $booking->isEligibleForCancellationRequest()) {
            throw ValidationException::withMessages([
                'status' => ['This booking can no longer be modified — the service has already started.'],
            ]);
        }

        if ($booking->hasPendingChangeRequest()) {
            throw ValidationException::withMessages([
                'status' => ['Another request for this booking is still waiting for the photographer\'s answer.'],
            ]);
        }

        if (! $booking->hasEnoughNoticeForChanges()) {
            throw ValidationException::withMessages([
                'status' => ['Modification requests must be made at least '.Booking::CHANGE_NOTICE_DAYS.' days before the event.'],
            ]);
        }

        // Keep only real changes to the allowed fields.
        $clean = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $new = $changes[$field];
            if (is_string($new)) {
                $new = trim($new);
                if ($new === '') {
                    $new = null;
                }
            }
            if ($new === null) {
                continue;
            }
            if ($field === 'guest_count') {
                $new = (int) $new;
            }

            if ((string) $new === trim((string) ($booking->{$field} ?? ''))) {
                continue;
            }

            $clean[$field] = $new;
        }

        if ($clean === []) {
            throw ValidationException::withMessages([
                'changes' => ['Nothing to update — what you entered is the same as the current booking.'],
            ]);
        }

        $booking->update([
            'modification_type' => 'details',
            'modification_reason' => $reason,
            'modification_changes' => $clean,
            'modification_requested_at' => now(),
            'modification_decision' => null,
            'modification_decided_at' => null,
        ]);

        $fresh = $booking->fresh();

        $fresh->photographer->notify(new BookingModificationRequestedNotification($fresh));

        $this->activityLogger->execute(
            causer: $fresh->client,
            subject: $fresh,
            action: 'booking.modification_requested',
            description: "Requested to update the details of booking #{$fresh->id} — reason: {$reason}",
            metadata: ['changes' => $clean, 'reason' => $reason],
        );

        return $fresh;
    }
}