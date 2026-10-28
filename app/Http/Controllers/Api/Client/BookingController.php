<?php

namespace App\Http\Controllers\Api\Client;

use App\Actions\Booking\CreateBookingAction;
use App\Actions\Booking\RequestBookingCancellationAction;
use App\Actions\Booking\RequestBookingExtensionAction;
use App\Actions\Booking\RequestBookingModificationAction;
use App\Actions\Booking\RequestBookingRescheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBookingRequest;
use App\Http\Requests\RequestBookingCancellationRequest;
use App\Http\Requests\RequestBookingExtensionRequest;
use App\Http\Requests\RequestBookingModificationRequest;
use App\Http\Requests\RequestBookingRescheduleRequest;
use App\Actions\Booking\ModifyBookingDetailsAction;
use App\Actions\Booking\RescheduleBookingAction;
use App\Http\Requests\ModifyBookingDetailsRequest;
use App\Http\Requests\RescheduleBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        app(\App\Actions\Booking\ExpireStaleBookingHoldsAction::class)->executeThrottled();

        return $this->success(
            BookingResource::collection($request->user()->bookingsAsClient()->latest()->get())
        );
    }

    public function store(CreateBookingRequest $request, CreateBookingAction $action)
    {
        $this->authorize('create', Booking::class);

        if (! $request->user()->hasVerifiedEmail()) {
            return $this->error('Please verify your email address before booking a photographer.', 403);
        }

        $data = $request->validated();
$budgetAmount = $data['budget_amount'] ?? null;
$budgetNote = $data['budget_note'] ?? null;
unset($data['budget_amount'], $data['budget_note']);

// The booking and its optional budget request succeed or fail together: a budget that is not
// lower than the package subtotal rolls the whole booking back with a clear error.
$booking = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $action, $data, $budgetAmount, $budgetNote) {
    $booking = $action->execute($request->user(), $data);

    if ($budgetAmount !== null && $budgetAmount !== '') {
        $booking = app(\App\Actions\Booking\SubmitBudgetRequestAction::class)
            ->execute($booking, $request->user(), (float) $budgetAmount, $budgetNote);
    }

    return $booking;
});

        return $this->success(new BookingResource($booking), 'Booking request submitted.', 201);
    }

    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        app(\App\Actions\Booking\ExpireStaleBookingHoldsAction::class)->executeThrottled();

        return $this->success(new BookingResource($booking->refresh()));
    }

    public function requestCancellation(RequestBookingCancellationRequest $request, Booking $booking, RequestBookingCancellationAction $action)
    {
        $this->authorize('requestCancellation', $booking);

        $booking = $action->execute($booking, $request->validated('reason'));

        return $this->success(
            new BookingResource($booking),
            $booking->status === \App\Enums\BookingStatus::Cancelled ? 'Booking cancelled.' : 'Cancellation requested. The photographer will review it.'
        );
    }

    public function reportNonCompletion(\App\Http\Requests\ReportBookingNonCompletionRequest $request, Booking $booking, \App\Actions\Booking\ReportBookingNonCompletionAction $action)
    {
        $this->authorize('requestCancellation', $booking); // same rule: must be this booking's client

        $booking = $action->execute(
            $booking,
            $request->user(),
            \App\Enums\BookingNonCompletionReason::from($request->validated('reason')),
            $request->validated('notes')
        );

        return $this->success(new BookingResource($booking), 'Reported.');
    }

        public function disputeNonCompletion(\App\Http\Requests\DisputeBookingNonCompletionRequest $request, Booking $booking, \App\Actions\Booking\DisputeBookingNonCompletionAction $action)
    {
        $this->authorize('requestCancellation', $booking);

        $booking = $action->execute($booking, $request->user(), $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Dispute submitted.');
    }

        public function acceptOffer(Request $request, Booking $booking, \App\Actions\Booking\AcceptSlotOfferAction $action)
    {
        abort_unless($request->user()->isClient() && $booking->client_id === $request->user()->id, 403);

        $booking = $action->execute($booking);

        return $this->success(new BookingResource($booking), 'Offer accepted. You can now pay to confirm the schedule.');
    }

    public function declineOffer(Request $request, Booking $booking, \App\Actions\Booking\DeclineSlotOfferAction $action)
    {
        abort_unless($request->user()->isClient() && $booking->client_id === $request->user()->id, 403);

        $booking = $action->execute($booking);

        return $this->success(new BookingResource($booking), 'Offer declined. Your booking is back on the waitlist.');
    }

    public function requestReschedule(RequestBookingRescheduleRequest $request, Booking $booking, RequestBookingRescheduleAction $action)
    {
        $this->authorize('requestReschedule', $booking);

        $booking = $action->execute($booking, $request->validated('event_date'), $request->validated('start_time'), $request->validated('reason'), $request->validated('type', 'standard'));

        return $this->success(new BookingResource($booking), 'Reschedule requested.');
    }

    /** Start times this booking could be moved to on a given date (what the client's time picker shows). */
    public function rescheduleSlots(\Illuminate\Http\Request $request, Booking $booking, RequestBookingRescheduleAction $action)
    {
        $this->authorize('requestReschedule', $booking);

        $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'type' => ['nullable', 'in:standard,weather,venue,agreed'],
        ]);

        return $this->success([
            'date' => $request->query('date'),
            'start_times' => $action->availableSlots($booking, $request->query('date'), $request->query('type', 'standard')),
        ]);
    }

    public function requestModification(RequestBookingModificationRequest $request, Booking $booking, RequestBookingModificationAction $action)
    {
        $this->authorize('modify', $booking);

        $booking = $action->execute($booking, $request->validated('changes'), $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Modification request submitted.');
    }

    public function requestExtension(RequestBookingExtensionRequest $request, Booking $booking, RequestBookingExtensionAction $action)
    {
        $this->authorize('requestExtension', $booking);

        $action->execute($booking, $request->validated('additional_hours'), $request->validated('schedule_id'));

        return $this->success(new BookingResource($booking->fresh()), 'Additional coverage requested. The photographer will review it.');
    }
}