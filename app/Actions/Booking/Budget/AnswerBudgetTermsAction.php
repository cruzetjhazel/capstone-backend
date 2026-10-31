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

/**
 * Client answers the photographer's proposed terms. Accepting is the ONLY
 * moment the booking's price changes. The booking itself stays Pending: the
 * photographer then accepts it at the new price like any other request.
 */
class AnswerBudgetTermsAction
{
    public function accept(User $client, Booking $booking): BookingBudgetRequest
    {
        return $this->answer($client, $booking, true);
    }

    public function reject(User $client, Booking $booking): BookingBudgetRequest
    {
        return $this->answer($client, $booking, false);
    }

    private function answer(User $client, Booking $booking, bool $accept): BookingBudgetRequest
    {
        if ((int) $booking->client_id !== (int) $client->id) {
            throw new AuthorizationException('This is not your booking.');
        }

        $request = DB::transaction(function () use ($booking, $accept) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'terms' => ['This booking is no longer pending, so these terms can no longer be answered.'],
                ]);
            }

            $request = $booking->budgetRequests()
                ->where('status', BudgetRequestStatus::Proposed->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'terms' => ['There are no photographer terms waiting for your answer.'],
                ]);
            }

            // Closing stale terms is saved here; the error is thrown after the commit so it isn't rolled back.
            if ($request->expireIfStale()) {
                return $request;
            }

            if ($accept) {
                $booking->update([
                    'subtotal' => $request->proposed_price,
                    'total_price' => $request->proposed_total,
                ]);
                $request->update(['status' => BudgetRequestStatus::Accepted]);
            } else {
                $request->update(['status' => BudgetRequestStatus::Rejected]);
            }

            return $request;
        });

        if ($request->status === BudgetRequestStatus::Expired) {
            throw ValidationException::withMessages(['terms' => ['These terms have expired.']]);
        }

        return $request;
    }
}