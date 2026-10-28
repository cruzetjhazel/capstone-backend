<?php

namespace App\Actions\Booking\Budget;

use App\Enums\BookingStatus;
use App\Enums\BudgetRequestStatus;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Photographer answers the client's budget: accept it, counter with adjusted
 * terms, or decline. Accepting and countering both only PROPOSE terms: the
 * booking price does not change until the client accepts them
 * (AnswerBudgetTermsAction).
 */
class RespondToBudgetRequestAction
{
    /**
     * @param array{action: string, proposed_price?: mixed, inclusions?: mixed, note?: ?string, reason?: ?string} $payload
     */
    public function execute(User $photographer, Booking $booking, array $payload): BookingBudgetRequest
    {
        if ((int) $booking->photographer_id !== (int) $photographer->id) {
            throw new AuthorizationException('This is not your booking.');
        }

        return DB::transaction(function () use ($booking, $payload) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'action' => ['This booking is no longer pending, so the budget can no longer be answered.'],
                ]);
            }

            $request = $booking->budgetRequests()
                ->where('status', BudgetRequestStatus::Requested->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'action' => ['There is no budget request waiting for your response.'],
                ]);
            }

            return match ($payload['action'] ?? null) {
                'accept' => $this->propose($booking, $request, (float) $request->budget_amount, [], $payload['note'] ?? null),
                'counter' => $this->counter($booking, $request, $payload),
                'decline' => $this->decline($request, (string) ($payload['reason'] ?? '')),
                default => throw ValidationException::withMessages(['action' => ['Unknown action.']]),
            };
        });
    }

    private function counter(Booking $booking, BookingBudgetRequest $request, array $payload): BookingBudgetRequest
    {
        $price = round((float) ($payload['proposed_price'] ?? 0), 2);
        $listed = (float) $booking->subtotal;

        if ($price <= 0 || ($listed > 0 && $price > $listed)) {
            throw ValidationException::withMessages([
                'proposed_price' => ['The counter price must be more than 0 and cannot be higher than the listed price.'],
            ]);
        }

        $inclusions = collect($payload['inclusions'] ?? [])
            ->map(fn ($i) => [
                'name' => trim((string) ($i['name'] ?? '')),
                'details' => filled($i['details'] ?? null) ? trim((string) $i['details']) : null,
            ])
            ->filter(fn ($i) => $i['name'] !== '')
            ->take(BookingBudgetRequest::MAX_INCLUSIONS)
            ->values()
            ->all();

        if ($inclusions === []) {
            throw ValidationException::withMessages([
                'inclusions' => ['Add at least one inclusion with a name.'],
            ]);
        }

        return $this->propose($booking, $request, $price, $inclusions, $payload['note'] ?? null);
    }

    private function propose(Booking $booking, BookingBudgetRequest $request, float $price, array $inclusions, ?string $note): BookingBudgetRequest
    {
        // Whatever the booking already adds on top of the package (the platform fee) stays on top.
        $fee = max(0.0, (float) $booking->total_price - (float) $booking->subtotal);

        $expires = Carbon::now()->addHours(BookingBudgetRequest::TERMS_VALID_HOURS);
        if ($booking->hold_expires_at !== null && $booking->hold_expires_at->lt($expires)) {
            $expires = $booking->hold_expires_at->copy(); // terms can't outlive the booking's own hold
        }
        if ($expires->lte(Carbon::now())) {
            throw ValidationException::withMessages([
                'action' => ['This booking\'s reservation window has ended, so terms can no longer be offered.'],
            ]);
        }

        $request->update([
            'status' => BudgetRequestStatus::Proposed,
            'proposed_price' => round($price, 2),
            'proposed_total' => round($price + $fee, 2),
            'inclusions' => $inclusions,
            'photographer_note' => $this->clean($note),
            'expires_at' => $expires,
            'responded_at' => Carbon::now(),
        ]);

        return $request;
    }

    private function decline(BookingBudgetRequest $request, string $reason): BookingBudgetRequest
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Please give the client a reason.']]);
        }

        $request->update([
            'status' => BudgetRequestStatus::Declined,
            'decline_reason' => trim($reason),
            'responded_at' => Carbon::now(),
        ]);

        return $request;
    }

    private function clean(?string $note): ?string
    {
        return $note !== null && trim($note) !== '' ? trim($note) : null;
    }
}