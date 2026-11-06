<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Notifications\Booking\ServiceTrackerUpdatedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RunServiceProgressTransitionsAction
{
    /** Philippine time (UTC+8, no daylight saving). Event dates and times are stored as plain local values. */
    public const TIMEZONE = 'Asia/Manila';

    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(): array
    {
        return [
            'moved_to_event_day' => $this->markEventDay(),
            'auto_completed' => $this->autoCompleteDelivered(),
        ];
    }

    private function markEventDay(): int
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();
        $moved = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('service_status', ServiceTrackerStatus::Upcoming)
            // Cheap pre-filter: only a booking with a session dated today or earlier can be due.
            ->where(function ($q) use ($today) {
                $q->whereDate('event_date', '<=', $today)
                    ->orWhereHas('schedules', fn ($s) => $s->whereDate('event_date', '<=', $today));
            })
            ->chunkById(100, function ($candidates) use (&$moved) {
                foreach ($candidates as $candidate) {
                    if ($this->advanceToEventDay($candidate->id)) {
                        $moved++;
                    }
                }
            });

        return $moved;
    }

    private function advanceToEventDay(int $bookingId): bool
    {
        $booking = DB::transaction(function () use ($bookingId) {
            // Re-read under a lock and re-check: a request or report may have landed since the query ran.
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

            if ($booking === null || ! $this->isDueForEventDay($booking)) {
                return null;
            }

            $booking->update([
                'service_status' => ServiceTrackerStatus::EventDay,
                'service_status_updated_at' => now(),
            ]);

            return $booking;
        });

        if ($booking === null) {
            return false;
        }

        $this->activityLogger->execute(
            causer: null,
            subject: $booking,
            action: 'booking.service_tracker_updated',
            description: "Booking #{$booking->id} automatically moved to Event Day.",
        );

        // Same client notification the manual step used to send. A failure here must not block the transition.
        try {
            $booking->client?->notify(new ServiceTrackerUpdatedNotification($booking->fresh()));
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * Confirmed, paid, still on Upcoming, nothing waiting for a decision that could move or end it,
     * and the earliest session has actually started (Manila time).
     */
    private function isDueForEventDay(Booking $booking): bool
    {
        if ($booking->status !== BookingStatus::Confirmed
            || $booking->service_status !== ServiceTrackerStatus::Upcoming
            || ! $booking->isPaymentSettled()) {
            return false;
        }

        // Pending cancellation, reschedule/postponement or modification request, or a pending no-show report.
        if ($booking->hasPendingChangeRequest() || $booking->hasPendingNoShowReport()) {
            return false;
        }

        $startsAt = $this->serviceStartsAt($booking);

        return $startsAt !== null && $startsAt->lte(Carbon::now(self::TIMEZONE));
    }

    /** Earliest session start (this booking's own schedule or any additional one), in Manila time. Never guessed. */
    private function serviceStartsAt(Booking $booking): ?Carbon
    {
        return $booking->allSchedules()
            ->filter(fn ($s) => $s->event_date !== null && $s->start_time !== null)
            ->map(fn ($s) => Carbon::parse(Carbon::parse($s->event_date)->toDateString().' '.$s->start_time, self::TIMEZONE))
            ->sortBy(fn (Carbon $c) => $c->getTimestamp())
            ->first();
    }

    /**
     * Delivered bookings the client never confirmed: once the client's 48-hour window
     * (scheduled end + 48h) has passed, mark them Completed. Reaching Delivered alone
     * never completes a booking.
     */
    private function autoCompleteDelivered(): int
    {
        $completed = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('service_status', ServiceTrackerStatus::Delivered)
            ->chunkById(100, function ($candidates) use (&$completed) {
                foreach ($candidates as $candidate) {
                    if ($this->autoComplete($candidate->id)) {
                        $completed++;
                    }
                }
            });

        return $completed;
    }

    private function autoComplete(int $bookingId): bool
    {
        try {
            return DB::transaction(function () use ($bookingId) {
                // Re-read under a lock and re-check: the client may have confirmed or reported since the query ran.
                $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

                if ($booking === null || ! $this->isDueForAutoCompletion($booking)) {
                    return false;
                }

                app(MarkServiceCompletedAction::class)->execute($booking, MarkServiceCompletedAction::BY_SYSTEM);

                return true;
            });
        } catch (ValidationException $e) {
            return false;
        }
    }

    /** Delivered, no no-show report waiting, and the 48-hour window has a real end that has passed (never guessed). */
    private function isDueForAutoCompletion(Booking $booking): bool
    {
        if (! $booking->canCompleteService() || $booking->hasPendingNoShowReport()) {
            return false;
        }

        $deadline = $booking->noShowReportDeadline();

        return $deadline !== null && now()->gt($deadline);
    }
}