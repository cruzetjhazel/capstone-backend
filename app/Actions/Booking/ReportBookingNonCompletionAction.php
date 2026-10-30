<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingNonCompletionReason;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReportBookingNonCompletionAction
{
    public const DISPUTE_WINDOW_HOURS = 16;

    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(Booking $booking, User $reporter, BookingNonCompletionReason $reason, ?string $notes): Booking
    {
        if ($booking->is_manual) {
            throw ValidationException::withMessages([
                'status' => ['Manual bookings cannot be reported as a no-show. Cancel the booking instead.'],
            ]);
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => ['Only a confirmed booking can be reported as non-completed.'],
            ]);
        }

        if ($booking->non_completion_reason) {
            throw ValidationException::withMessages([
                'status' => ['This booking already has a no-show report.'],
            ]);
        }
        if ($booking->non_completion_review_status === 'overturned') {
            throw ValidationException::withMessages([
                'status' => ['A previous report on this booking was reviewed and overturned by an admin.'],
            ]);
        }

        $isClientReporter = $reporter->id === $booking->client_id;

        $serviceStart = $booking->serviceStartsAt();
        if ($serviceStart === null || $serviceStart->isFuture()) {
            throw ValidationException::withMessages([
                'status' => ['This booking\'s scheduled time has not started yet.'],
            ]);
        }

        if ($isClientReporter) {
            // Client -> photographer no-show: from the scheduled start until the scheduled
            // service END TIME + 48 hours. Checked here (not just in the UI) and independent of the
            // tracker stage, so a photographer moving the booking to Editing cannot cut it short.
            $deadline = $booking->noShowReportDeadline();

            if ($deadline === null) {
                throw ValidationException::withMessages([
                    'status' => ['This booking has no confirmed end time yet, so a no-show report cannot be filed.'],
                ]);
            }

            if (now()->gt($deadline)) {
                throw ValidationException::withMessages([
                    'status' => ['The reporting period for this booking ended on '.$deadline->format('M j, Y g:i A').'.'],
                ]);
            }
        } elseif (in_array($booking->service_status, [ServiceTrackerStatus::Delivered], true)) {
            // Photographer -> client no-show keeps its existing rule.
            throw ValidationException::withMessages([
                'status' => ['This booking has already progressed past the point of a no-show report.'],
            ]);
        }

        // A client can only report the photographer; the photographer can
        // only report the client — prevents self-serving reports.
        $isClient = $reporter->id === $booking->client_id;
        $isPhotographer = $reporter->id === $booking->photographer_id;
        if (($isClient && $reason !== BookingNonCompletionReason::PhotographerNoShow)
            || ($isPhotographer && $reason !== BookingNonCompletionReason::ClientNoShow)
            || (! $isClient && ! $isPhotographer)) {
            throw ValidationException::withMessages([
                'reason' => ['You can only report the other party\'s non-attendance.'],
            ]);
        }

        // NOT cancelled here. The booking stays Confirmed and goes to the admin for review.
        // The other party has 16 hours to dispute.
        $booking->non_completion_reason = $reason;
        $booking->non_completion_reported_at = now();
        $booking->non_completion_reported_by = $reporter->id;
        $booking->non_completion_dispute_deadline_at = now()->addHours(self::DISPUTE_WINDOW_HOURS);
        $booking->non_completion_review_status = 'pending_admin';
        $booking->non_completion_disputed_at = null;
        $booking->non_completion_dispute_reason = null;
        $booking->non_completion_resolved_at = null;
        if ($notes) {
            $booking->cancellation_reason = $notes; // reporter's statement; cleared if admin dismisses
        }
        $booking->save();

        $fresh = $booking->fresh();
        $other = $isClient ? $fresh->photographer : $fresh->client;
        $other->notify(new \App\Notifications\Booking\NonCompletionNotification($fresh, 'reported'));
        // Tell every admin there is a report to review.
        \App\Models\User::query()->get()
            ->filter(fn (User $u) => $u->isAdministrator())
            ->each(fn (User $admin) => $admin->notify(new \App\Notifications\Booking\NonCompletionNotification($fresh, 'admin_review')));
        $this->activityLogger->execute(
            causer: $reporter,
            subject: $fresh,
            action: 'booking.non_completion_reported',
            description: "Booking #{$fresh->id} reported as {$reason->value} (waiting for admin review)",
            metadata: ['reason' => $reason->value, 'notes' => $notes],
        );

        return $fresh;
    }
}