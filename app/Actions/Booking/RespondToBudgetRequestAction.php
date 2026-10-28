<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Models\User;
use App\Notifications\Booking\BudgetNegotiationNotification;
use App\Services\Photographer\Booking\BookingDeadlineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The photographer answers a budget request: accept the budget (same package), counter with a
 * custom price + inclusions, or decline with a reason.
 *
 * Nothing on the booking's price changes here. accept / counter only create PROPOSED terms that
 * the client must accept. They also set the request's own deadline and the booking's
 * hold_expires_at to min(now + 24h, first session start).
 *
 * Lock order: photographer row, booking row, then the request row.
 */
class RespondToBudgetRequestAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected BookingDeadlineService $deadlines,
    ) {
    }

    /** @param array{action:string, proposed_price?:mixed, inclusions?:array, note?:?string, reason?:?string} $data */
    public function execute(Booking $booking, User $photographer, array $data): Booking
    {
        $action = $data['action'];

        $fresh = DB::transaction(function () use ($booking, $action, $data) {
            User::where('id', $booking->photographer_id)->lockForUpdate()->firstOrFail();
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending || $locked->isHoldExpired()) {
                throw ValidationException::withMessages([
                    'status' => ['This booking request is no longer waiting for your response.'],
                ]);
            }

            $request = BookingBudgetRequest::openFor($locked, lock: true);

            if ($request === null || $request->status !== BookingBudgetRequest::REQUESTED) {
                throw ValidationException::withMessages([
                    'action' => ['There is no budget request waiting for your response on this booking.'],
                ]);
            }

            if ($action === 'decline') {
                $request->update([
                    'status' => BookingBudgetRequest::DECLINED,
                    'decline_reason' => $data['reason'],
                    'open_booking_id' => null,
                    'responded_at' => now(),
                ]);

                return $locked->fresh();
            }

            $listed = (float) $locked->subtotal;

            if ($action === 'accept') {
                $price = (float) $request->budget_amount;
                $inclusions = null; // same package, same inclusions
            } else {
                $price = round((float) $data['proposed_price'], 2);

                if ($price <= 0 || $price > $listed) {
                    throw ValidationException::withMessages([
                        'proposed_price' => ['The counter price must be more than 0 and not higher than the listed price.'],
                    ]);
                }

                $inclusions = collect($data['inclusions'])
                    ->map(fn ($i) => [
                        'name' => trim($i['name']),
                        'details' => isset($i['details']) && trim((string) $i['details']) !== '' ? trim($i['details']) : null,
                    ])
                    ->values()
                    ->all();
            }

            // Throws (and rolls back) if the event is too close to ever collect a payment.
            $this->deadlines->paymentDeadline($locked);

            $expires = now()->addHours(BookingBudgetRequest::COUNTER_WINDOW_HOURS);
            $firstSession = $this->deadlines->firstSessionStart($locked);

            if ($firstSession->lessThan($expires)) {
                $expires = $firstSession;
            }

            $fee = (float) config('platform.fee', 30.00);

            $request->update([
                'status' => BookingBudgetRequest::PROPOSED,
                'proposed_subtotal' => $price,
                'proposed_total' => round($price + $fee, 2),
                'proposed_inclusions' => $inclusions,
                'photographer_note' => $data['note'] ?? null,
                'expires_at' => $expires,
                'responded_at' => now(),
            ]);

            // The booking stays Pending at the listed price; only its response window moves.
            $locked->update(['hold_expires_at' => $expires]);

            return $locked->fresh();
        });

        $request = BookingBudgetRequest::where('booking_id', $fresh->id)->latest('id')->first();

        if ($action === 'decline') {
            $fresh->client->notify(new BudgetNegotiationNotification(
                $fresh,
                'booking.budget_declined',
                "{$fresh->photographer->name} declined your budget for booking #{$fresh->id}: {$request->decline_reason}",
            ));
            $summary = 'declined the budget';
        } else {
            $fresh->client->notify(new BudgetNegotiationNotification(
                $fresh,
                'booking.budget_proposed',
                "{$fresh->photographer->name} sent you terms for booking #{$fresh->id}: ₱".number_format((float) $request->proposed_subtotal, 2)
                    .' (plus the platform fee). Review and accept them to continue to payment.',
            ));
            $summary = $action === 'accept' ? 'accepted the budget' : 'countered the budget';
        }

        $this->activityLogger->execute(
            causer: $photographer,
            subject: $fresh,
            action: 'booking.budget_'.($action === 'decline' ? 'declined' : 'proposed'),
            description: "Photographer {$summary} for booking #{$fresh->id}.",
        );

        return $fresh;
    }
}