<?php

namespace App\Services\Photographer;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\AvailabilityWindow;
use App\Models\BlockedDate;
use App\Models\BookingHour;
use App\Models\Booking;
use App\Models\BookingSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * A date is bookable by default — it only becomes unavailable if the
 * photographer has explicitly blocked it (BlockedDate, full-day or partial)
 * or a Booking with a CONFIRMED payment already occupies the requested time.
 * Approval of any booking request is still entirely up to the photographer;
 * this service only determines whether a request can be *sent* for a given
 * date/time.
 *
 * Business rule (see SlotConflictService for the full rationale): a slot is
 * only ever truly "taken" once a booking for it has a confirmed payment
 * (partially or fully paid). A Pending request, or an accepted-but-unpaid
 * Confirmed booking, must NOT remove the slot from the public calendar —
 * otherwise a second client could never even submit a competing request,
 * which would make the system first-come-first-served in practice despite
 * CreateBookingAction explicitly allowing multiple overlapping requests.
 * getAvailableStartTimes() is also what CreateBookingAction::
 * assertSlotIsAvailable() checks a new request's start time against, so
 * this one filter keeps the calendar and the booking-creation guard in
 * sync automatically.
 *
 * Bookable hours for a date come from two layers, both optional:
 *  - BookingHour: the photographer's recurring "usual hours" per weekday
 *    (multiple periods per day supported, e.g. 6-12 and 2-8). If a
 *    photographer has never configured any BookingHour rows, every day
 *    defaults to fully open (00:00-24:00) for backward compatibility. Once
 *    at least one BookingHour row exists, any weekday with none stays
 *    closed for that reason (e.g. "Sunday: unavailable").
 *  - AvailabilityWindow: a one-off ADDITIVE period for one specific date,
 *    layered on top of whatever BookingHour resolves to that day — e.g. a
 *    photographer normally closed Sundays opening up one specific Sunday.
 *
 * BlockedDate (full-day or partial) still carves out unavailable time from
 * whatever periods result above, same as before.
 */
class AvailabilityService
{
    // Only a Confirmed booking whose payment has actually been settled
    // (partially or fully paid) holds the calendar slot. Pending requests
    // and accepted-but-unpaid Confirmed bookings do NOT block the public
    // calendar — see the class docblock.
    private const BLOCKING_BOOKING_STATUSES = [
        BookingStatus::Confirmed,
    ];

    private const BLOCKING_PAYMENT_STATUSES = [
        BookingPaymentStatus::PartiallyPaid,
        BookingPaymentStatus::FullyPaid,
    ];

    private const DEFAULT_SLOT_STEP_MINUTES = 30;

    /**
     * Per-day availability for a month.
     *
     * @return array<string, "past"|"available"|"partial"|"unavailable"> keyed by "Y-m-d"
     */
    public function getMonthSummary(User $photographer, string $start, string $end, int $durationMinutes): array
    {
        $stepMinutes = $this->slotStepMinutes($photographer);

        $bookingHoursByWeekday = BookingHour::query()
            ->where('user_id', $photographer->id)
            ->get()
            ->groupBy('day_of_week');

        $hasAnyBookingHours = $photographer->booking_hours_initialized || $bookingHoursByWeekday->isNotEmpty();

        $windowsByDate = AvailabilityWindow::query()
            ->where('user_id', $photographer->id)
            ->whereBetween('date', [$start, $end])
            ->get()
            ->groupBy(fn ($w) => $w->date->format('Y-m-d'));

        $blocksByDate = BlockedDate::query()
            ->where('user_id', $photographer->id)
            ->whereBetween('date', [$start, $end])
            ->get()
            ->groupBy(fn ($b) => $b->date->format('Y-m-d'));

        $bookingsByDate = Booking::query()
            ->where('photographer_id', $photographer->id)
            ->whereIn('status', self::BLOCKING_BOOKING_STATUSES)
            ->where(fn ($p) => $p->whereIn('payment_status', self::BLOCKING_PAYMENT_STATUSES)->orWhere('is_manual', true))
            ->whereBetween('event_date', [$start, $end])
            ->get()
            ->groupBy(fn ($b) => $b->event_date->format('Y-m-d'));

        // Multi-schedule bookings (Prenup + Wedding on separate dates, say)
        // occupy dates that never touch the booking's own event_date column
        // — merge in every other booking's additional BookingSchedule rows
        // so those dates are correctly blocked too. dayStatus()/
        // startTimesForPeriods() only ever read ->event_date/->start_time/
        // ->end_time, which BookingSchedule exposes identically to Booking,
        // so the two model types can share one collection.
        foreach ($this->additionalSchedulesByDate($photographer, $start, $end) as $dateStr => $schedules) {
            $bookingsByDate[$dateStr] = $bookingsByDate->get($dateStr, collect())->concat($schedules);
        }

        $summary = [];
        $today = Carbon::today();

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $dateStr = $day->format('Y-m-d');

            if ($day->lt($today)) {
                $summary[$dateStr] = 'past';
                continue;
            }

            $periods = $this->resolvePeriods(
                $dateStr,
                $day->dayOfWeek,
                $bookingHoursByWeekday,
                $hasAnyBookingHours,
                $windowsByDate->get($dateStr, collect())
            );

            $dayBlocks = $blocksByDate->get($dateStr, collect());
            $dayBookings = $bookingsByDate->get($dateStr, collect());

            $summary[$dateStr] = $this->dayStatus($periods, $dayBlocks, $dayBookings, $durationMinutes, $stepMinutes);
        }

        return $summary;
    }

    /**
     * All valid booking start times on a single date for a given duration.
     *
     * @return string[] "HH:mm" values, in the photographer's configured step
     */
    public function getAvailableStartTimes(User $photographer, string $date, int $durationMinutes, ?int $excludeBookingId = null, ?int $minNoticeHours = null): array
    {
        $stepMinutes = $this->slotStepMinutes($photographer);
        $carbonDate = Carbon::parse($date);

        $bookingHoursForDay = BookingHour::query()
            ->where('user_id', $photographer->id)
            ->where('day_of_week', $carbonDate->dayOfWeek)
            ->get();

        $hasAnyBookingHours = $photographer->booking_hours_initialized
            || BookingHour::query()->where('user_id', $photographer->id)->exists();

        $windowsForDate = AvailabilityWindow::query()
            ->where('user_id', $photographer->id)
            ->where('date', $date)
            ->get();

        $blocks = BlockedDate::query()
            ->where('user_id', $photographer->id)
            ->where('date', $date)
            ->get();
        $bookings = Booking::query()
            ->where('photographer_id', $photographer->id)
            ->whereIn('status', self::BLOCKING_BOOKING_STATUSES)
            ->where(fn ($p) => $p->whereIn('payment_status', self::BLOCKING_PAYMENT_STATUSES)->orWhere('is_manual', true))
            ->where('event_date', $date)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->get();

        // See getMonthSummary()'s equivalent merge — other bookings'
        // additional schedule entries for this same date must block it too.
        $bookings = $bookings->concat(
            $this->additionalSchedulesByDate($photographer, $date, $date)->get($date, collect())
                ->reject(fn ($s) => $excludeBookingId !== null && (int) $s->booking_id === $excludeBookingId)
        );

        $periods = $this->resolvePeriods(
            $date,
            $carbonDate->dayOfWeek,
            collect([$carbonDate->dayOfWeek => $bookingHoursForDay]),
            $hasAnyBookingHours,
            $windowsForDate
        );

        return $this->availableStartTimes($date, $periods, $blocks, $bookings, $durationMinutes, $stepMinutes, $minNoticeHours);
    }

    /** Buffer saved with the booking (fixed package or custom package snapshot). */
    private function bufferMinutesFor($booking): int
    {
        $parent = $booking instanceof BookingSchedule ? $booking->booking : $booking;
        if (! $parent) {
            return 0;
        }

        $snapshot = $parent->is_custom_package ? $parent->custom_package_snapshot : $parent->package_snapshot;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return (int) (((array) $snapshot)['buffer_minutes'] ?? 0);
    }

    private function slotStepMinutes(User $photographer): int
    {
        return $photographer->slot_interval_minutes ?: self::DEFAULT_SLOT_STEP_MINUTES;
    }

    /**
     * Other bookings' Schedule 2+ entries (BookingSchedule rows) within
     * [start, end], grouped by "Y-m-d" — same blocking-status filter as
     * the primary-schedule query above, applied through the parent
     * booking relation since BookingSchedule itself carries no
     * status/payment columns of its own.
     *
     * @return Collection<string, Collection<int, BookingSchedule>>
     */
    private function additionalSchedulesByDate(User $photographer, string $start, string $end): Collection
    {
        return BookingSchedule::query()
            ->whereBetween('event_date', [$start, $end])
            ->whereHas('booking', fn ($q) => $q
                ->where('photographer_id', $photographer->id)
                ->whereIn('status', self::BLOCKING_BOOKING_STATUSES)
                ->where(fn ($p) => $p->whereIn('payment_status', self::BLOCKING_PAYMENT_STATUSES)->orWhere('is_manual', true))
            )
            ->with('booking')
            ->get()
            ->groupBy(fn (BookingSchedule $s) => $s->event_date->format('Y-m-d'));
    }

    /**
     * Resolves the bookable periods for one date as a list of [start, end]
     * Carbon pairs (plural — a day can have multiple periods, e.g. a
     * morning and an evening block).
     *
     * @return array<array{0: Carbon, 1: Carbon}>
     */
    private function resolvePeriods(
        string $dateStr,
        int $dayOfWeek,
        Collection $bookingHoursByWeekday,
        bool $hasAnyBookingHours,
        Collection $windowsForDate
    ): array {
        $hoursForDay = $bookingHoursByWeekday->get($dayOfWeek, collect());

        if ($hoursForDay->isNotEmpty()) {
            $periods = $hoursForDay
                ->map(fn (BookingHour $h) => [
                    Carbon::parse("{$dateStr} {$h->start_time}"),
                    Carbon::parse("{$dateStr} {$h->end_time}"),
                ])
                ->values()
                ->all();
        } elseif (! $hasAnyBookingHours) {
            // Photographer hasn't configured usual hours at all yet —
            // preserve the old "fully open" default.
            $periods = [[Carbon::parse("{$dateStr} 07:00"), Carbon::parse("{$dateStr} 22:00")]];
        } else {
            // Usual hours ARE configured, just not for this weekday
            // (e.g. "Sunday: unavailable") — closed unless a one-off
            // AvailabilityWindow opens it below.
            $periods = [];
        }

        // One-off windows are additive on top of the above, even on an
        // otherwise-closed day.
        foreach ($windowsForDate as $window) {
            $periods[] = [
                Carbon::parse("{$dateStr} {$window->start_time}"),
                Carbon::parse("{$dateStr} {$window->end_time}"),
            ];
        }

        return $periods;
    }

    private function dayStatus(array $periods, Collection $blocks, Collection $bookings, int $durationMinutes, int $stepMinutes): string
    {
        if (empty($periods)) {
            return 'unavailable';
        }

        // Full-day block still voids the whole day outright.
        if ($blocks->contains(fn (BlockedDate $b) => $b->isFullDay())) {
            return 'unavailable';
        }

        $dateStr = null; // periods carry their own dates via Carbon instances.

        $openSlots = count($this->startTimesForPeriods($periods, $blocks, $bookings, $durationMinutes, $stepMinutes));

        if ($openSlots === 0) {
            return 'unavailable';
        }

        // Counted the same way as $openSlots (no blocks/bookings applied) so
        // overlapping periods — e.g. a one-off AvailabilityWindow layered on
        // top of an already fully-open fallback day — can't inflate this
        // beyond what openSlots could ever reach. Summing each period's slot
        // count independently (the old approach) double-counted overlapping
        // time and produced false "partial" statuses on days nothing was
        // actually booked or blocked.
        $totalSlots = count($this->startTimesForPeriods($periods, collect(), collect(), $durationMinutes, $stepMinutes));

        return $openSlots < $totalSlots ? 'partial' : 'available';
    }

    /**
     * Public-facing single-date entry point kept separate from the
     * multi-period walker so getAvailableStartTimes() can format dates.
     */
    private function availableStartTimes(string $dateStr, array $periods, Collection $blocks, Collection $bookings, int $durationMinutes, int $stepMinutes, ?int $minNoticeHours = null): array
    {
        if (empty($periods)) {
            return [];
        }

        if ($blocks->contains(fn (BlockedDate $b) => $b->isFullDay())) {
            return [];
        }

        return $this->startTimesForPeriods($periods, $blocks, $bookings, $durationMinutes, $stepMinutes, $dateStr, $minNoticeHours);
    }

    /**
     * Walks each period in stepMinutes increments and keeps any start time
     * whose [start, start+duration] doesn't overlap a block or booking, and
     * doesn't spill past the end of its own period.
     */
    private function startTimesForPeriods(array $periods, Collection $blocks, Collection $bookings, int $durationMinutes, int $stepMinutes, ?string $dateStrForBookings = null, ?int $minNoticeHours = null): array
    {
        $busyRanges = [];

        foreach ($blocks as $block) {
            if ($block->isFullDay()) {
                continue; // handled by the early-return in callers
            }
            $dateStr = $block->date->format('Y-m-d');
            $busyRanges[] = [
                Carbon::parse("{$dateStr} {$block->start_time}"),
                Carbon::parse("{$dateStr} {$block->end_time}"),
            ];
        }

        foreach ($bookings as $booking) {
            $bookingDateStr = $booking->event_date->format('Y-m-d');
            $bookingStart = Carbon::parse("{$bookingDateStr} {$booking->start_time}");
            // Bookings without a stored end_time are assumed to occupy the
            // same duration being queried for, as a conservative fallback.
            $bookingEnd = $booking->end_time
                ? Carbon::parse("{$bookingDateStr} {$booking->end_time}")
                : $bookingStart->copy()->addMinutes($stepMinutes);
            // Keep the photographer's buffer AFTER an existing booking clear too.
            // (end_time is coverage only; the buffer lives in the booking's snapshot.)
            if ($booking->end_time) {
                $bookingEnd->addMinutes($this->bufferMinutesFor($booking));
            }
            $busyRanges[] = [$bookingStart, $bookingEnd];
        }

        $slots = [];
        // Too-short notice can't be paid for (see BookingDeadlineService), so don't offer it.
        $noticeHours = $minNoticeHours ?? (int) config('platform.min_notice_hours', 5);
        $now = Carbon::now()->startOfMinute();
        $earliestStart = $now->copy()->addHours($noticeHours);

        foreach ($periods as [$periodStart, $periodEnd]) {
            $cursor = $periodStart->copy();

            while (true) {
                $slotEnd = $cursor->copy()->addMinutes($durationMinutes);

                if ($slotEnd->gt($periodEnd)) {
                    break;
                }

                $overlaps = false;
                foreach ($busyRanges as $range) {
                    if ($cursor->lt($range[1]) && $slotEnd->gt($range[0])) {
                        $overlaps = true;
                        break;
                    }
                }

                $formatted = $cursor->format('H:i');
                // Never offer a start time that has already passed (matters for today's date).
                if (! $overlaps && ($noticeHours > 0 ? $cursor->gte($earliestStart) : $cursor->gt($now)) && ! in_array($formatted, $slots, true)) {
                    $slots[] = $formatted;
                }

                $cursor->addMinutes($stepMinutes);
            }
        }

        sort($slots);

        return $slots;
    }
}