<?php

namespace App\Actions\Booking\Budget;

use App\Enums\BookingStatus;
use App\Enums\BudgetRequestStatus;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendBudgetRequestAction
{
    /** Client sends (another) budget for a booking that already exists. */
    public function execute(User $client, Booking $booking, float $amount, ?string $note = null): BookingBudgetRequest
    {
        if ((int) $booking->client_id !== (int) $client->id) {
            throw new AuthorizationException('This is not your booking.');
        }

        return DB::transaction(function () use ($booking, $amount, $note) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'budget_amount' => ['A budget can only be sent while the booking is still pending.'],
                ]);
            }

            // Terms the client never answered no longer block a new request.
            $booking->budgetRequests()
                ->where('status', BudgetRequestStatus::Proposed->value)
                ->get()
                ->each(fn (BookingBudgetRequest $r) => $r->expireIfStale());

            if ($booking->hasOpenBudgetNegotiation()) {
                throw ValidationException::withMessages([
                    'budget_amount' => ['You already have a budget request waiting on this booking.'],
                ]);
            }

            if ($booking->budgetRequests()->count() >= BookingBudgetRequest::MAX_REQUESTS_PER_BOOKING) {
                throw ValidationException::withMessages([
                    'budget_amount' => ['You have used all ' . BookingBudgetRequest::MAX_REQUESTS_PER_BOOKING . ' budget requests for this booking.'],
                ]);
            }

            return $this->create($booking, $amount, $note);
        });
    }

    /** A budget typed on the booking form: saved together with the new booking (see CreateBookingAction). */
    public function createForNewBooking(Booking $booking, float $amount, ?string $note = null): BookingBudgetRequest
    {
        return $this->create($booking, $amount, $note);
    }

    private function create(Booking $booking, float $amount, ?string $note): BookingBudgetRequest
    {
        $listed = (float) $booking->subtotal;

        if ($amount <= 0 || ($listed > 0 && $amount >= $listed)) {
            throw ValidationException::withMessages([
                'budget_amount' => ['Your budget must be more than 0 and lower than the listed price.'],
            ]);
        }

        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return $booking->budgetRequests()->create([
            'status' => BudgetRequestStatus::Requested,
            'budget_amount' => round($amount, 2),
            'client_note' => $note,
        ]);
    }
}