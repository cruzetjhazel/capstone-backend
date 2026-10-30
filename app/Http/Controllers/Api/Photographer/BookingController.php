<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Booking\AcceptBookingAction;
use App\Actions\Booking\AccommodateBookingAction;
use App\Actions\Booking\DecideBookingCancellationAction;
use App\Actions\Booking\DecideBookingExtensionAction;
use App\Actions\Booking\DecideBookingRescheduleAction;
use App\Actions\Booking\RejectBookingAction;
use App\Enums\BookingExtensionStatus;
use App\Enums\CancellationDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccommodateBookingRequest;
use App\Http\Requests\DeclineBookingExtensionRequest;
use App\Http\Requests\RejectBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingExtension;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        app(\App\Actions\Booking\ExpireStaleBookingHoldsAction::class)->executeThrottled();

        return $this->success(
            BookingResource::collection($request->user()->bookingsAsPhotographer()->latest()->get())
        );
    }

    /** Records a booking the photographer took outside the platform, for one of their walk-in clients. */
    public function storeManual(\App\Http\Requests\CreateManualBookingRequest $request, \App\Actions\Booking\CreateManualBookingAction $action)
    {
        $booking = $action->execute($request->user(), $request->validated());

        return $this->success(new BookingResource($booking), 'Manual booking recorded.', 201);
    }

    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        app(\App\Actions\Booking\ExpireStaleBookingHoldsAction::class)->executeThrottled();

        return $this->success(new BookingResource($booking->refresh()));
    }

    public function accept(Booking $booking, AcceptBookingAction $action)
    {
        $this->authorize('respond', $booking);

        $booking = $action->execute($booking);

        return $this->success(
            new BookingResource($booking),
            $booking->status === \App\Enums\BookingStatus::Waitlisted
                ? 'Another paid booking already holds this schedule, so this request was added to the waitlist instead of being confirmed.'
                : 'Booking accepted.'
        );
    }

    public function reject(RejectBookingRequest $request, Booking $booking, RejectBookingAction $action)
    {
        $this->authorize('respond', $booking);

        $booking = $action->execute($booking, $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Booking request declined.');
    }

        public function reportNonCompletion(\App\Http\Requests\ReportBookingNonCompletionRequest $request, Booking $booking, \App\Actions\Booking\ReportBookingNonCompletionAction $action)
    {
        $this->authorize('view', $booking); // photographer already scoped to own bookings elsewhere in this controller

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
        $this->authorize('view', $booking);

        $booking = $action->execute($booking, $request->user(), $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Dispute submitted.');
    }

    public function approveCancellation(Booking $booking, DecideBookingCancellationAction $action)
    {
        $this->authorize('decideCancellation', $booking);

        $booking = $action->execute($booking, CancellationDecision::Approved);

        return $this->success(new BookingResource($booking), 'Cancellation approved.');
    }

    public function rejectCancellation(Booking $booking, DecideBookingCancellationAction $action)
    {
        $this->authorize('decideCancellation', $booking);

        $booking = $action->execute($booking, CancellationDecision::Rejected);

        return $this->success(new BookingResource($booking), 'Cancellation request declined.');
    }

    public function accommodationCandidates(Booking $booking)
    {
        $this->authorize('accommodate', $booking);

        // Already ordered oldest request first (see Booking::accommodationCandidates()).
        $candidates = $booking->accommodationCandidates()->get();

        return $this->success(BookingResource::collection($candidates));
    }

    public function accommodate(AccommodateBookingRequest $request, Booking $booking, AccommodateBookingAction $action)
    {
        $this->authorize('accommodate', $booking);

        $candidate = Booking::findOrFail($request->validated('candidate_booking_id'));
        $this->authorize('accommodate', $candidate);

        $accommodated = $action->execute($booking, $candidate);

        return $this->success(new BookingResource($accommodated), 'Booking accommodated. The client can now proceed with payment.');
    }

    public function approveReschedule(Booking $booking, DecideBookingRescheduleAction $action)
    {
        $this->authorize('decideReschedule', $booking);

        return $this->success(new BookingResource($action->execute($booking, CancellationDecision::Approved)), 'Reschedule approved.');
    }

    public function rejectReschedule(Booking $booking, DecideBookingRescheduleAction $action)
    {
        $this->authorize('decideReschedule', $booking);

        return $this->success(new BookingResource($action->execute($booking, CancellationDecision::Rejected)), 'Reschedule request declined.');
    }

    public function approveModification(Booking $booking, \App\Actions\Booking\DecideBookingModificationAction $action)
    {
        $this->authorize('decideReschedule', $booking);

        return $this->success(new BookingResource($action->execute($booking, CancellationDecision::Approved)), 'Modification approved.');
    }

    public function rejectModification(Booking $booking, \App\Actions\Booking\DecideBookingModificationAction $action)
    {
        $this->authorize('decideReschedule', $booking);

        return $this->success(new BookingResource($action->execute($booking, CancellationDecision::Rejected)), 'Modification request declined.');
    }

    /** The photographer cancels a CONFIRMED booking (to turn down a new request they use "decline"). */
    public function cancel(RejectBookingRequest $request, Booking $booking, \App\Actions\Booking\CancelBookingByPhotographerAction $action)
    {
        $this->authorize('respond', $booking);

        $booking = $action->execute($booking, $request->validated('reason'));

        return $this->success(new BookingResource($booking), 'Booking cancelled.');
    }

    public function approveExtension(Booking $booking, BookingExtension $extension, DecideBookingExtensionAction $action)
    {
        $this->authorize('decideExtension', $booking);
        abort_unless($extension->booking_id === $booking->id, 404);

        $action->execute($extension, BookingExtensionStatus::Approved);

        return $this->success(new BookingResource($booking->fresh()), 'Extension approved. The client has been notified of the additional charge.');
    }

    public function declineExtension(DeclineBookingExtensionRequest $request, Booking $booking, BookingExtension $extension, DecideBookingExtensionAction $action)
    {
        $this->authorize('decideExtension', $booking);
        abort_unless($extension->booking_id === $booking->id, 404);

        $action->execute($extension, BookingExtensionStatus::Declined, $request->validated('reason'));

        return $this->success(new BookingResource($booking->fresh()), 'Extension declined.');
    }
}