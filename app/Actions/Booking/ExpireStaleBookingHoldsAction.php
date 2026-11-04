<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\Booking\SlotOfferEndedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpireStaleBookingHoldsAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

        /** A lapsed offer passes the slot to the next eligible client, never back to the lapsed one. */
    protected function passOnLapsedOffer(?int $releasedId): void
    {
        if ($releasedId === null) {
            return;
        }

        $released = Booking::find($releasedId);

        if ($released !== null) {
            app(OfferReleasedSlotAction::class)->execute($released);
        }
    }

    public function execute(): int
    {
           $count = $this->expireUndecidedRequests() + $this->expireUnpaidApprovals() + $this->expireWaitlisted();

   // Close budget requests whose booking expired / ended, or whose own deadline passed.
   \App\Models\BookingBudgetRequest::expireStale();

   return $count;
    }

    /**
     * Same as execute(), but runs at most once every 15 seconds. Called when
     * bookings are read, so a missed deadline is applied even when the
     * scheduler is not running (local XAMPP, a stopped cron, etc.).
     */
    public function executeThrottled(): int
    {
        if (! \Illuminate\Support\Facades\Cache::add('bookings:expire-stale:throttle', 1, 15)) {
            return 0;
        }

        // Safety net when the scheduler is not running: a booking that has reached its
        // start time still moves to Event Day as soon as bookings are read.
        try {
            app(\App\Actions\Booking\RunServiceProgressTransitionsAction::class)->execute();
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->execute();
    }

    /**
     * A waitlisted booking never got the schedule and its event time has now passed. It
     * moves to Expired with a clear reason; any submitted payment record is left untouched.
     * (A waitlisted booking has no payment deadline of its own, so it never expires earlier.)
     */
    protected function expireWaitlisted(): int
    {
        $bookings = Booking::query()
            ->where('status', BookingStatus::Waitlisted)
            ->whereRaw("CONCAT(event_date, ' ', start_time) < ?", [now()->format('Y-m-d H:i:s')])
            ->get();

        foreach ($bookings as $booking) {
            $booking->update([
                'status' => BookingStatus::Expired,
                'cancellation_reason' => 'The requested date passed before the schedule became available.',
                'hold_expires_at' => null,
                'slot_offered_at' => null,
            ]);

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.expired_waitlisted',
                description: "Booking #{$booking->id} expired — it stayed on the waitlist until the event date passed.",
            );
        }

        return $bookings->count();
    }

    /** Photographer neither approved nor rejected within the window (or the event already started). */
    protected function expireUndecidedRequests(): int
    {
        $now = now();

        $bookings = Booking::with('client')
            ->where('status', BookingStatus::Pending)
            ->where(function ($q) use ($now) {
                $q->where('hold_expires_at', '<=', $now)
                  ->orWhereRaw("CONCAT(event_date, ' ', start_time) < ?", [$now->format('Y-m-d H:i:s')]);
            })
            ->get();

        foreach ($bookings as $booking) {
            $booking->update([
                'status' => BookingStatus::Expired,
                'cancellation_reason' => 'Request expired — the photographer did not respond in time.',
                'hold_expires_at' => null,
            ]);

            $booking->client?->notify(new \App\Notifications\Booking\BookingExpiredNotification($booking, 'no_response'));

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.expired',
                description: "Booking #{$booking->id} expired — no photographer decision within the response window.",
            );
        }

        return $bookings->count();
    }

    /**
     * Accepted but the client never paid by the deadline. Only rows still at
     * payment_status = pending are touched, so a payment awaiting verification
     * (pending_verification) or already paid is never expired. Each row is re-read
     * under the same locks the payment actions use, so a payment submitted at the
     * last second can never be overwritten by this job.
     *
     *  - Ordinary accepted booking  -> Expired.
     *  - Booking OFFERED a released schedule (slot_offered_at set) -> back to the
     *    WAITLIST, so the photographer can offer the slot to another client.
     *    (If its event date has already passed, it expires instead.)
     */
    protected function expireUnpaidApprovals(): int
    {
        $candidates = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('payment_status', BookingPaymentStatus::Pending)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get(['id', 'photographer_id']);

        $changed = 0;

        foreach ($candidates as $row) {
            $outcome = DB::transaction(function () use ($row) {
                User::where('id', $row->photographer_id)->lockForUpdate()->firstOrFail();
                $booking = Booking::whereKey($row->id)->lockForUpdate()->firstOrFail();

                // Re-check under the lock: the client may have paid, or the booking moved on.
                if ($booking->status !== BookingStatus::Confirmed
                    || $booking->payment_status !== BookingPaymentStatus::Pending
                    || $booking->hold_expires_at === null
                    || $booking->hold_expires_at->isFuture()) {
                    return null;
                }

                $eventStart = Carbon::parse($booking->event_date->toDateString().' '.$booking->start_time);

                if ($booking->slot_offered_at !== null && $eventStart->isFuture()) {
                    $booking->update([
                        'status' => BookingStatus::Waitlisted,
                        'hold_expires_at' => null,
                        'slot_offered_at' => null,
                        'superseded_by_booking_id' => null,
                        'slot_offer_accepted_at' => null,
                    ]);

                        $offer = \App\Models\BookingSlotOffer::closeOpenFor($booking, \App\Models\BookingSlotOffer::LAPSED);

    return ['booking' => $booking->fresh(), 'back_to_waitlist' => true, 'released_id' => $offer?->released_booking_id];
                }

                $booking->update([
                    'status' => BookingStatus::Expired,
                    'cancellation_reason' => 'Booking expired — the reservation payment was not completed in time.',
                    'hold_expires_at' => null,
                    'slot_offered_at' => null,
                ]);

                    \App\Models\BookingSlotOffer::closeOpenFor($booking, \App\Models\BookingSlotOffer::LAPSED);

    return ['booking' => $booking->fresh(), 'back_to_waitlist' => false];
            });

            if ($outcome === null) {
                continue;
            }

            $changed++;
            $booking = $outcome['booking'];

                if ($outcome['back_to_waitlist']) {
        $this->passOnLapsedOffer($outcome['released_id'] ?? null);
                $booking->client->notify(new SlotOfferEndedNotification(
                    $booking, 'lapsed',
                    'The deadline for the schedule offered to you has passed. Your booking is back on the waitlist and will not be offered this schedule again automatically.'
                ));
                $booking->photographer->notify(new SlotOfferEndedNotification(
                    $booking, 'lapsed',
                    "The offer for booking #{$booking->id} lapsed because it was not paid in time. It is back on the waitlist and the slot was passed to the next eligible client."
                ));

                $this->activityLogger->execute(
                    causer: null,
                    subject: $booking,
                    action: 'booking.offer_lapsed',
                    description: "Offer for booking #{$booking->id} lapsed unpaid — returned to the waitlist.",
                );

                continue;
            }

            $booking->client?->notify(new \App\Notifications\Booking\BookingExpiredNotification($booking, 'unpaid'));

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.expired_unpaid',
                description: "Booking #{$booking->id} expired — reservation payment not received before the deadline.",
            );
        }

        return $changed;
    }
}