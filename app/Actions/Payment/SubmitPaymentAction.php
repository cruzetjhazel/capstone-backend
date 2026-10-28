<?php

namespace App\Actions\Payment;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Photographer\Booking\SlotConflictService;
use Illuminate\Validation\ValidationException;

/**
 * Records a Client's submitted GCash payment. The platform never receives the
 * money and does not check GCash: the submission is saved as awaiting the
 * photographer's confirmation (matching_status NotMatched, booking
 * payment_status PendingVerification). Only the photographer can verify or
 * reject it (ManuallyVerifyPaymentAction / RejectPaymentAction).
 */
class SubmitPaymentAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected SlotConflictService $slotConflictService,
    ) {
    }

    public function execute(Booking $booking, array $data): Payment
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages([
                'booking' => ['This booking is not currently awaiting payment.'],
            ]);
        }

        if ($booking->payment_status !== BookingPaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'booking' => ['A payment has already been submitted for this booking.'],
            ]);
        }

        $plan = PaymentPlan::from($data['plan']);
        $expectedAmount = $booking->onlineAmountDueFor($plan);

        if (round((float) $data['amount'], 2) !== round($expectedAmount, 2)) {
            throw ValidationException::withMessages([
                'amount' => ["The amount paid must match the {$plan->value} payment amount of ".number_format($expectedAmount, 2).'.'],
            ]);
        }

        // A GCash reference number is unique across all of GCash, so it can't be
        // used for a different booking while it is awaiting review or after it has
        // been verified. Only rejected attempts free it up again, and the same
        // booking may resubmit its own reference after a rejection (typo fix).
        $alreadyUsedElsewhere = Payment::where('reference_number', $data['reference_number'])
            ->where('booking_id', '!=', $booking->id)
            ->whereIn('matching_status', [
                PaymentMatchingStatus::NotMatched, // awaiting photographer review
                PaymentMatchingStatus::Matched,
                PaymentMatchingStatus::ManuallyVerified,
            ])
            ->exists();

        if ($alreadyUsedElsewhere) {
            throw ValidationException::withMessages([
                'reference_number' => ['This GCash reference number has already been used for a different booking.'],
            ]);
        }

        // Lock the booking so a double-click / two tabs can't create two
        // submissions for the same booking.
        $payment = DB::transaction(function () use ($booking, $data, $plan) {
            // Same lock order as payment verification (photographer row, then the booking).
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'booking' => ['This booking is not currently awaiting payment.'],
                ]);
            }

            // The payment window is enforced here too, not only by the background expiry job.
              if ($locked->isAwaitingOfferAcceptance()) {
        throw ValidationException::withMessages([
            'booking' => ['Please accept the offered schedule first. Payment opens once you accept it.'],
        ]);
    }

    if ($locked->hold_expires_at !== null && $locked->hold_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'booking' => ['The payment window for this booking has passed.'],
                ]);
            }

            if ($this->slotConflictService->findPaidConflict($locked) !== null) {
                throw ValidationException::withMessages([
                    'booking' => ['Another booking currently holds this schedule, so payment is not available right now. Your reservation may be considered if the slot becomes available.'],
                ]);
            }

            if ($locked->payment_status !== BookingPaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'booking' => ['A payment has already been submitted for this booking.'],
                ]);
            }

            $payment = Payment::create([
                'booking_id' => $locked->id,
                'client_id' => $locked->client_id,
                'photographer_id' => $locked->photographer_id,
                'type' => PaymentType::Online,
                'method' => 'gcash',
                'plan' => $plan->value,
                'amount' => $data['amount'],
                'reference_number' => $data['reference_number'],
                'active_reference_number' => $data['reference_number'], // DB-unique until the payment is rejected
                'payer_name' => $data['payer_name'],
                'payment_date' => $data['payment_date'],
                'matching_status' => PaymentMatchingStatus::NotMatched, // = awaiting photographer confirmation
            ]);

            $locked->update([
                'payment_plan' => $plan,
                'payment_status' => BookingPaymentStatus::PendingVerification,
            ]);

            return $payment;
        });

        $booking->refresh();
        $freshPayment = $payment->fresh();

        $booking->client->notify(new \App\Notifications\Payment\PaymentPendingVerificationNotification($freshPayment));
        $booking->photographer->notify(new \App\Notifications\Payment\PaymentNeedsReviewNotification($freshPayment));

        $this->activityLogger->execute(
            causer: $booking->client,
            subject: $freshPayment,
            action: 'payment.submitted_unmatched', // key kept so existing log labels don't break
            description: "Submitted {$plan->value} payment for booking #{$booking->id}, awaiting photographer confirmation",
            metadata: ['amount' => $data['amount'], 'plan' => $plan->value, 'reference_number' => $data['reference_number']],
        );

        return $freshPayment;
    }
}