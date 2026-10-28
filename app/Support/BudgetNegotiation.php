<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\BudgetRequestStatus;
use App\Models\Booking;
use App\Models\BookingBudgetRequest;

/**
 * Builds the budget_request / budget_actions JSON the frontend panels read
 * (see BudgetRequestInfo / BudgetActions in negotiationService.ts).
 */
final class BudgetNegotiation
{
    /** The newest budget request of a booking (any status), after closing stale terms. */
    public static function latest(Booking $booking): ?BookingBudgetRequest
    {
        $request = $booking->budgetRequests()->latest('id')->first();
        $request?->expireIfStale();

        return $request;
    }

    public static function requestPayload(BookingBudgetRequest $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'is_open' => $request->isOpen(),
            'budget_amount' => (float) $request->budget_amount,
            'client_note' => $request->client_note,
            'proposed_price' => $request->proposed_price !== null ? (float) $request->proposed_price : null,
            'proposed_total' => $request->proposed_total !== null ? (float) $request->proposed_total : null,
            'inclusions' => array_values($request->inclusions ?? []),
            'photographer_note' => $request->photographer_note,
            'decline_reason' => $request->decline_reason,
            'expires_at' => $request->expires_at?->toISOString(),
            'responded_at' => $request->responded_at?->toISOString(),
            'created_at' => $request->created_at?->toISOString(),
        ];
    }

    /** @param 'client'|'photographer' $viewer */
    public static function actions(Booking $booking, string $viewer): array
    {
        $open = $booking->budgetRequests()
            ->whereIn('status', BudgetRequestStatus::openValues())
            ->latest('id')
            ->first();
        $open?->expireIfStale();
        if ($open !== null && ! $open->isOpen()) {
            $open = null;
        }

        $used = $booking->budgetRequests()->count();
        $pending = $booking->status === BookingStatus::Pending;

        return [
            'can_request' => $viewer === 'client' && $pending && $open === null
                && $used < BookingBudgetRequest::MAX_REQUESTS_PER_BOOKING,
            'requests_remaining' => max(0, BookingBudgetRequest::MAX_REQUESTS_PER_BOOKING - $used),
            'can_respond' => $viewer === 'photographer' && $pending
                && $open?->status === BudgetRequestStatus::Requested,
            'can_answer_terms' => $viewer === 'client' && $pending
                && $open?->status === BudgetRequestStatus::Proposed,
        ];
    }

    /**
     * Drop this into a booking's JSON (BookingResource etc.).
     *
     * @param 'client'|'photographer' $viewer
     * @return array{budget_request: ?array, budget_actions: array}
     */
    public static function forBooking(Booking $booking, string $viewer): array
    {
        $latest = self::latest($booking);

        return [
            'budget_request' => $latest ? self::requestPayload($latest) : null,
            'budget_actions' => self::actions($booking, $viewer),
        ];
    }
}