<?php

namespace App\Actions\Payment;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMatchingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Photographer\Booking\BookingDeadlineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectPaymentAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected BookingDeadlineService $deadlines,
    ) {
    }

    public function execute(Payment $payment, User $verifier, string $notes): Payment
    {
        if (! $payment->isAwaitingManualReview()) {
            throw ValidationException::withMessages([
                'payment' => ['Only payments awaiting photographer confirmation can be rejected.'],
            ]);
        }

        $photographerId = $payment->booking->photographer_id;

        DB::transaction(function () use ($payment, $verifier, $notes, $photographerId) {
            // Same lock order as every other slot/payment action: photographer row, then rows.
            User::where('id', $photographerId)->lockForUpdate()->firstOrFail();

            $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $lockedPayment->isAwaitingManualReview()) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment has already been reviewed.'],
                ]);
            }

            $booking = Booking::whereKey($lockedPayment->booking_id)->lockForUpdate()->firstOrFail();

            $lockedPayment->update([
                'matching_status' => PaymentMatchingStatus::Rejected,                'matching_status' => PaymentMatchingStatus::Rejected,
                'active_reference_number' => null, // frees the reference for reuse                'verified_by' => $verifier->id,
                'verified_at' => now(),
                'verification_action' => 'rejected',
                'verification_notes' => $notes,
            ]);

            // Reopen the booking for a fresh payment submission attempt.
            $updates = ['payment_status' => BookingPaymentStatus::Pending];

            // An offered booking that already had a reference under review has no payment
            // deadline (see AccommodateBookingAction). Without one it could never lapse and
            // would block the slot forever, so a fresh payment window starts now.
            if ($booking->status === BookingStatus::Confirmed && $booking->hold_expires_at === null) {
                $updates['hold_expires_at'] = $this->freshPaymentDeadline($booking);
            }

            $booking->update($updates);
        });

        $freshPayment = $payment->fresh();
        $freshPayment->client->notify(new \App\Notifications\Payment\PaymentRejectedNotification($freshPayment));

        $this->activityLogger->execute(
            causer: $verifier,
            subject: $freshPayment,
            action: 'payment.rejected',
            description: "Rejected payment for booking #{$freshPayment->booking_id}",
            metadata: ['notes' => $notes],
        );

        return $freshPayment;
    }

    /** Normal payment window; if the event is too close for that, the minimum window instead. */
    private function freshPaymentDeadline(Booking $booking): \Carbon\Carbon
    {
        try {
            return $this->deadlines->paymentDeadline($booking);
        } catch (ValidationException) {
            return now()->addMinutes(BookingDeadlineService::MIN_PAYMENT_WINDOW_MINUTES);
        }
    }
}