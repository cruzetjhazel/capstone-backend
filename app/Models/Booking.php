<?php

namespace App\Models;

use App\Enums\BookingLocationType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\BudgetRequestStatus;
use App\Enums\CancellationDecision;
use App\Enums\PaymentPlan;
use App\Enums\ServiceTrackerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use App\Observers\BookingObserver;
use App\Enums\BookingNonCompletionReason;

#[ObservedBy([BookingObserver::class])]
class Booking extends Model
{
    use HasFactory;
    protected $attributes = [
        'payment_status' => 'pending',
    ];

    protected $fillable = [
        'client_id', 'photographer_id', 'package_id',
        'is_custom_package', 'package_snapshot', 'custom_package_snapshot', 'add_ons_snapshot',
        'event_type', 'custom_event_type', 'event_date', 'start_time', 'end_time', 'duration_minutes',
        'location_type', 'province_id', 'city_municipality_id', 'barangay_id',
        'event_address', 'guest_count', 'special_requests',
        'subtotal', 'total_price', 'status', 'hold_expires_at',
        'rejection_reason', 'cancellation_reason', 'cancellation_requested_at',
        'cancellation_decision', 'cancellation_decided_at', 'superseded_by_booking_id',
        'non_completion_reason', 'non_completion_reported_at',
        'non_completion_dispute_deadline_at', 'non_completion_disputed_at',
        'non_completion_dispute_reason', 'non_completion_dispute_report_id',
        'non_completion_resolution', 'non_completion_resolved_at',
        'non_completion_reported_by', 'non_completion_dispute_deadline_at',
        'non_completion_disputed_at', 'non_completion_dispute_reason',
        'non_completion_review_status', 'non_completion_admin_notes', 'non_completion_resolved_at',
        'requested_event_date', 'requested_start_time', 'reschedule_requested_at', 'reschedule_type',
        'reschedule_decision', 'reschedule_decided_at',
        'modification_type', 'modification_reason', 'modification_requested_at',
        'reschedule_reason', 'modification_changes', 'modification_decision', 'modification_decided_at', 'cancelled_by',
        'payment_plan', 'payment_status',
        'service_status', 'service_status_updated_at',
        'custom_hours', 'slot_offered_at', 'slot_offer_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_custom_package' => 'boolean',
            'package_snapshot' => 'array',
            'custom_package_snapshot' => 'array',
            'add_ons_snapshot' => 'array',
            'event_date' => 'date:Y-m-d',
            'guest_count' => 'integer',
            'subtotal' => 'decimal:2',
            'total_price' => 'decimal:2',
            'status' => BookingStatus::class,
            'location_type' => BookingLocationType::class,
            'cancellation_decision' => CancellationDecision::class,
            'hold_expires_at' => 'datetime',
               'slot_offered_at' => 'datetime',
   'slot_offer_accepted_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancellation_decided_at' => 'datetime',
            'non_completion_reported_at' => 'datetime',
            'non_completion_dispute_deadline_at' => 'datetime',
            'non_completion_disputed_at' => 'datetime',
            'non_completion_resolved_at' => 'datetime',
            'requested_event_date' => 'date:Y-m-d',
            'reschedule_requested_at' => 'datetime',
            'reschedule_decision' => CancellationDecision::class,
            'reschedule_decided_at' => 'datetime',
            'modification_requested_at' => 'datetime',
            'modification_changes' => 'array',
            'modification_decision' => CancellationDecision::class,
            'modification_decided_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'payment_plan' => PaymentPlan::class,
            'payment_status' => BookingPaymentStatus::class,
            'service_status' => ServiceTrackerStatus::class,
            'service_status_updated_at' => 'datetime',
            'non_completion_reason' => \App\Enums\BookingNonCompletionReason::class,
            'non_completion_reported_at' => 'datetime',
            'non_completion_dispute_deadline_at' => 'datetime',
            'non_completion_disputed_at' => 'datetime',
            'non_completion_resolved_at' => 'datetime',
        ];

    
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(LocationProvince::class, 'province_id');
    }

    public function cityMunicipality(): BelongsTo
    {
        return $this->belongsTo(LocationCityMunicipality::class, 'city_municipality_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(LocationBarangay::class, 'barangay_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The booking whose confirmed payment auto-declined this one for the
     * same photographer/slot (see SlotConflictService::releaseConflictingBookings).
     * Null once this booking has been accommodated again, or if it was
     * never superseded in the first place (e.g. an ordinary reject/cancel).
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'superseded_by_booking_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(BookingExtension::class);
    }

    /** Every approved move of this booking (normal reschedules and weather/venue postponements). */
    public function reschedules(): HasMany
    {
        return $this->hasMany(BookingReschedule::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(BookingSchedule::class)->orderBy('sort_order');
    }

    /**
     * Schedule 1 (this booking's own event_date/start_time/end_time —
     * unchanged, computed by CreateBookingAction exactly as before) plus
     * every additional BookingSchedule row, as one ordered collection.
     * BookingResource, the extension-target picker, and anything else
     * that needs "every occupied period for this booking" reads through
     * this rather than special-casing where Schedule 1's columns live.
     */
    public function allSchedules(): \Illuminate\Support\Collection
    {
        $primary = new BookingSchedule([
            'label' => $this->custom_event_type ?: ucfirst(str_replace('_', ' ', $this->event_type ?? '')),
            'event_date' => $this->event_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
        ]);
        // id intentionally stays null (unsaved model) — BookingScheduleResource
        // reads a null id as "this is the primary schedule, not a
        // booking_schedules row."

        return collect([$primary])->concat($this->schedules);
    }

    /**
     * Extension requests are only meaningful for sliding-hours custom
     * bookings (custom_hours is set — see CreateBookingAction::
     * resolveCustomPackage) and only once the booking is actually
     * Confirmed. Fixed-package and flat-base-fee custom bookings have no
     * hourly rate to bill additional coverage against.
     */
    public function isEligibleForExtensionRequest(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && in_array($this->service_status, [null, ServiceTrackerStatus::Upcoming, ServiceTrackerStatus::EventDay], true)
            && $this->is_custom_package
            && $this->custom_hours !== null;
    }

    public function hasPendingExtensionRequest(): bool
    {
        return $this->extensions()->where('status', \App\Enums\BookingExtensionStatus::Pending)->exists();
    }

    /**
     * Sum of every APPROVED extension's additional_charge — kept separate
     * from total_price so the original booking total is never silently
     * increased (see CreateBookingAction / spec). Callers that need "what
     * the client owes altogether" should add this to total_price
     * themselves rather than this method folding it in.
     */
    public function approvedExtensionCharge(): float
    {
        return (float) $this->extensions()
            ->where('status', \App\Enums\BookingExtensionStatus::Approved)
            ->sum('additional_charge');
    }

    public function isHoldExpired(): bool
    {
        return $this->status === BookingStatus::Pending
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isPast();
    }

    public function hasPendingCancellationRequest(): bool
    {
        return $this->cancellation_requested_at !== null && $this->cancellation_decision === null;
    }

    public function hasPendingRescheduleRequest(): bool
    {
        return $this->reschedule_requested_at !== null && $this->reschedule_decision === null;
    }

    public function hasPendingModificationRequest(): bool
    {
        return $this->modification_requested_at !== null && $this->modification_decision === null;
    }

    /** Minimum notice (days before the event) for reschedule / modification requests. */
    public const CHANGE_NOTICE_DAYS = 7;

    /** Photographer buffer that was saved with this booking (0 if none). */
    public function bufferMinutes(): int
    {
        return (int) ($this->package_snapshot['buffer_minutes'] ?? $this->custom_package_snapshot['buffer_minutes'] ?? 0);
    }

    public function hasEnoughNoticeForChanges(): bool
    {
        return \Carbon\Carbon::parse($this->event_date)->startOfDay()
            ->gte(\Carbon\Carbon::today()->addDays(self::CHANGE_NOTICE_DAYS));
    }

    /** Any cancellation / reschedule / modification request still waiting for the photographer. */
    public function hasPendingChangeRequest(): bool
    {
        return $this->hasPendingCancellationRequest()
            || $this->hasPendingRescheduleRequest()
            || $this->hasPendingModificationRequest();
    }

    /**
     * The amount due online for a given payment plan (§8.2, §8.8).
     * Half Payment = 50% online + 50% remaining balance; Full Payment = 100% online.
     */
    public function onlineAmountDueFor(PaymentPlan $plan): float
    {
        return $plan === PaymentPlan::Full
            ? (float) $this->total_price
            : round((float) $this->total_price / 2, 2);
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()
            ->where(function ($q) {
                $q->whereNull('matching_status')
                  ->orWhere('matching_status', '!=', \App\Enums\PaymentMatchingStatus::Rejected->value);
            })
            ->sum('amount');
    }

    /**
     * Total Booking Amount - Online Payment = Remaining Balance (§8.8).
     */
    public function remainingBalance(): float
    {
        return max(0.0, round((float) $this->total_price - $this->totalPaid(), 2));
    }

    /**
     * True once the required online payment for a Half-Payment booking has
     * been submitted (Confirmed + payment_plan=Half) but the onsite
     * remaining balance hasn't been recorded yet (§8.9).
     */
    public function isEligibleForOnsitePayment(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->payment_status !== BookingPaymentStatus::FullyPaid;
    }

    /**
     * Service Tracker (Module 10, §8.10-8.11) is only meaningful once a
     * booking has actually been confirmed — or is already Completed via the
     * tracker reaching its final stage.
     */
    public function canManageServiceTracker(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    /**
     * True only once the photographer's explicit completion action becomes
     * valid: still Confirmed, and the service tracker has reached its final
     * stage (Delivered). BookingStatus never flips to Completed on its own —
     * see MarkServiceCompletedAction, the only caller of this check.
     */
    public function canCompleteService(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->service_status === ServiceTrackerStatus::Delivered;
    }

    /**
     * Cancellation is only available before the service has actually
     * started: a Pending request, or a Confirmed booking that's still
     * pre-event (service_status null or Upcoming — event_day/editing/
     * delivered/completed all count as "started").
     */
    public function isEligibleForCancellationRequest(): bool
    {
        if (in_array($this->status, [BookingStatus::Pending, BookingStatus::Waitlisted], true)) {
            return true;
        }

        return $this->status === BookingStatus::Confirmed
            && in_array($this->service_status, [null, ServiceTrackerStatus::Upcoming], true);
    }

    /**
     * True once the booking's required payment (per its payment plan) has
     * been settled — used by BookingObserver to auto-advance the service
     * tracker from null to Upcoming.
     */
       /** An offered schedule the client has not accepted yet: it is not payable until they accept. */
   public function isAwaitingOfferAcceptance(): bool
   {
       return $this->status === BookingStatus::Confirmed
           && $this->slot_offered_at !== null
           && $this->slot_offer_accepted_at === null
           && $this->payment_status === BookingPaymentStatus::Pending;
   }

   public function slotOffers(): HasMany
   {
       return $this->hasMany(BookingSlotOffer::class);
   }

      public function budgetRequests(): HasMany
   {
       return $this->hasMany(BookingBudgetRequest::class);
   }

    // ── Budget negotiation (see BookingBudgetRequest and App\Support\BudgetNegotiation) ──

    /** Photographer terms the client has not answered yet (an expired offer no longer counts). */
    public function hasBudgetTermsAwaitingClient(): bool
    {
        $this->expireStaleBudgetTerms();

        return $this->budgetRequests()->where('status', BudgetRequestStatus::Proposed->value)->exists();
    }

    /** A budget the photographer has not answered, or terms the client has not answered. */
    public function hasOpenBudgetNegotiation(): bool
    {
        $this->expireStaleBudgetTerms();

        return $this->budgetRequests()->whereIn('status', BudgetRequestStatus::openValues())->exists();
    }

    /** Close any open budget request, e.g. because the booking was accepted at the listed price. */
    public function supersedeOpenBudgetRequests(): void
    {
        $this->budgetRequests()
            ->whereIn('status', BudgetRequestStatus::openValues())
            ->update(['status' => BudgetRequestStatus::Superseded->value]);
    }

    private function expireStaleBudgetTerms(): void
    {
        foreach ($this->budgetRequests()->where('status', BudgetRequestStatus::Proposed->value)->get() as $request) {
            $request->expireIfStale();
        }
    }

   public function isPaymentSettled(): bool
    {
        return in_array($this->payment_status, [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid], true);
    }
    /**
     * Every photography session of this booking as a time window, INCLUDING the photographer's
     * buffer after it. This is the same rule the public calendar uses (AvailabilityService):
     * a booking occupies [start, end + buffer]. A session whose duration is not confirmed yet
     * (end_time null) is skipped: we never guess a window for it.
     *
     * @return \Illuminate\Support\Collection<int, array{start: \Carbon\Carbon, end: \Carbon\Carbon}>
     */
    public function occupiedWindows(): \Illuminate\Support\Collection
    {
        $buffer = $this->bufferMinutes();

        return $this->allSchedules()
            ->filter(fn ($s) => $s->end_time !== null)
            ->map(function ($s) use ($buffer) {
                $date = \Carbon\Carbon::parse($s->event_date)->toDateString();
                $start = \Carbon\Carbon::parse($date.' '.$s->start_time);
                $end = \Carbon\Carbon::parse($date.' '.$s->end_time);

                // A session that runs past midnight (e.g. 23:00 - 01:00) ends the next day.
                if ($end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                return ['start' => $start, 'end' => $end->addMinutes($buffer)];
            })
            ->values();
    }

    /**
     * Other bookings of the SAME photographer, limited to $statuses, whose occupied windows
     * (session + buffer, see occupiedWindows()) overlap ANY occupied window of this booking.
     * Two windows that merely touch (one ends exactly when the other starts) do not overlap.
     *
     * @param  array<int, BookingStatus>  $statuses
     */
    public function overlappingBookings(array $statuses): \Illuminate\Database\Eloquent\Collection
    {
        $mine = $this->occupiedWindows();

        if ($mine->isEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        // Pre-filter in SQL by date (a day either side, because a buffer or a late session can
        // spill over midnight), then compare the exact windows in PHP.
        $dates = $mine
            ->flatMap(fn ($w) => [
                $w['start']->copy()->subDay()->toDateString(),
                $w['start']->toDateString(),
                $w['end']->toDateString(),
                $w['end']->copy()->addDay()->toDateString(),
            ])
            ->unique()
            ->values()
            ->all();

        $candidates = Booking::query()
            ->where('photographer_id', $this->photographer_id)
            ->where('id', '!=', $this->id)
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($dates) {
                $q->whereIn('event_date', $dates)
                  ->orWhereHas('schedules', fn ($s) => $s->whereIn('event_date', $dates));
            })
            ->with('schedules')
            ->get();

        return $candidates->filter(function (Booking $other) use ($mine) {
            foreach ($other->occupiedWindows() as $theirs) {
                foreach ($mine as $w) {
                    if ($w['start']->lt($theirs['end']) && $w['end']->gt($theirs['start'])) {
                        return true;
                    }
                }
            }

            return false;
        })->values();
    }

    /**
     * Waitlisted bookings (same photographer) whose schedule (including buffer time) overlaps
     * ANY session of this booking, OLDEST REQUEST FIRST. Meant to be called on a booking that
     * held the slot and was cancelled: these are the clients who lost the slot to it and are
     * still waiting.
     *
     * Priority rule (explicit and consistent): created_at ascending, then id. The
     * photographer is offered this list in that order and the UI pre-selects the first.
     * Only bookings that are still Waitlisted are returned, so anything that has since
     * expired, been cancelled/declined or been confirmed is never reactivated, and
     * bookings whose event date has already passed are left out.
     */
    public function accommodationCandidates(): \Illuminate\Database\Eloquent\Builder
    {
        $today = now()->toDateString();

        $ids = $this->overlappingBookings([BookingStatus::Waitlisted])
            ->filter(fn (Booking $b) => \Carbon\Carbon::parse($b->event_date)->toDateString() >= $today)
            ->pluck('id')
            ->all();

        return Booking::query()
            ->whereIn('id', $ids)
            ->oldest()
            ->orderBy('id')
            ->with('client');
    }
        /**
     * Completed, or the photographer failed to show up (client can review that).
     * A client no-show is never reviewable: the client didn't attend the
     * service, so they must not be able to rate it. An admin-overturned
     * report clears non_completion_reason and returns to Confirmed, so it
     * isn't reviewable either until it is actually Completed.
     */
    public function isReviewable(): bool
    {
        return $this->status === BookingStatus::Completed
            || ($this->status === BookingStatus::NoShow
                && $this->non_completion_reason === BookingNonCompletionReason::PhotographerNoShow);
    }

    /** Hours after the scheduled service END TIME during which the client may report a photographer no-show. */
    public const NO_SHOW_REPORT_WINDOW_HOURS = 48;

    /** Start of the earliest session (this booking's own schedule or any additional one). */
    public function serviceStartsAt(): ?\Carbon\Carbon
    {
        return $this->allSchedules()
            ->filter(fn ($s) => $s->start_time !== null)
            ->map(fn ($s) => \Carbon\Carbon::parse(\Carbon\Carbon::parse($s->event_date)->toDateString().' '.$s->start_time))
            ->sortBy(fn (\Carbon\Carbon $c) => $c->timestamp)
            ->first();
    }

    /** End of the latest session. Null while any session's duration is still unconfirmed (never guessed). */
    public function serviceEndsAt(): ?\Carbon\Carbon
    {
        $schedules = $this->allSchedules();

        if ($schedules->contains(fn ($s) => $s->end_time === null)) {
            return null;
        }

        return $schedules
            ->map(fn ($s) => \Carbon\Carbon::parse(\Carbon\Carbon::parse($s->event_date)->toDateString().' '.$s->end_time))
            ->sortByDesc(fn (\Carbon\Carbon $c) => $c->timestamp)
            ->first();
    }

    /**
     * When the WHOLE service is over: the end of the latest session (so the last day of
     * a multi-day booking). A session whose end time is still unconfirmed counts as
     * running until the end of its event day.
     */
    public function serviceFinishesAt(): \Carbon\Carbon
    {
        return $this->allSchedules()
            ->map(function ($s) {
                $date = \Carbon\Carbon::parse($s->event_date)->toDateString();

                return $s->end_time !== null
                    ? \Carbon\Carbon::parse($date.' '.$s->end_time)
                    : \Carbon\Carbon::parse($date)->endOfDay();
            })
            ->sortByDesc(fn (\Carbon\Carbon $c) => $c->timestamp)
            ->first();
    }

    /** Scheduled service END TIME + 48 hours (not midnight, not the start time, not "when the button appeared"). */
    public function noShowReportDeadline(): ?\Carbon\Carbon
    {
        return $this->serviceEndsAt()?->copy()->addHours(self::NO_SHOW_REPORT_WINDOW_HOURS);
    }

    public function isWithinNoShowReportWindow(): bool
    {
        $start = $this->serviceStartsAt();
        $deadline = $this->noShowReportDeadline();

        return $start !== null && $deadline !== null && now()->gte($start) && now()->lte($deadline);
    }

    /**
     * While the client can still report a photographer no-show, the photographer cannot
     * close the booking as Completed. Null = nothing blocks completion (window over, never
     * reportable because no end time, or a report was already decided).
     */
    public function completionBlockedUntil(): ?\Carbon\Carbon
    {
        if ($this->non_completion_reason !== null || $this->non_completion_review_status === 'overturned') {
            return null;
        }

        $deadline = $this->noShowReportDeadline();

        return $deadline !== null && now()->lte($deadline) ? $deadline : null;
    }

    /** A no-show report (either side) is waiting for the admin. */
    public function hasPendingNoShowReport(): bool
    {
        return $this->non_completion_review_status === 'pending_admin';
    }

    /** Client may report a photographer no-show: Confirmed, never reported before, inside the 48h window. Independent of the tracker stage. */
    public function canClientReportPhotographerNoShow(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->non_completion_reason === null
            && $this->non_completion_review_status !== 'overturned'
            && $this->isWithinNoShowReportWindow();
    }

    /** Weather / venue / agreed postponement: a paid booking that has not reached Editing yet and has no pending no-show report. */
    public function isEligibleForPostponement(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && in_array($this->service_status, [ServiceTrackerStatus::Upcoming, ServiceTrackerStatus::EventDay], true)
            && ! $this->hasPendingNoShowReport();
    }

    /**
     * One place that answers "which client actions are available" — the backend
     * actions enforce the same rules, and BookingResource sends this to the frontend.
     */
    public function clientActions(): array
    {
        $open = $this->isEligibleForCancellationRequest();
        // A waitlisted booking can be cancelled, but not modified or moved while it has no schedule.
        $changeable = $open && $this->status !== BookingStatus::Waitlisted;
        $noPendingChange = ! $this->hasPendingChangeRequest();
        $singleSession = $this->end_time !== null && ! $this->schedules()->exists();
        $deadline = $this->noShowReportDeadline();
        $started = $this->serviceStartsAt()?->isPast() ?? false;

        return [
            'can_modify' => $changeable && $noPendingChange && $this->hasEnoughNoticeForChanges(),
            'can_reschedule' => $changeable && $noPendingChange && $singleSession && $this->hasEnoughNoticeForChanges(),
            'can_postpone' => $this->isEligibleForPostponement() && $noPendingChange && $singleSession,
            'can_cancel' => $open && ! $this->hasPendingCancellationRequest(),
            'can_report_photographer_no_show' => $this->canClientReportPhotographerNoShow(),
            'no_show_report_deadline' => $deadline?->toISOString(),
            'no_show_report_expired' => in_array($this->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)
                && $this->non_completion_reason === null
                && $started
                && $deadline !== null
                && now()->gt($deadline),
        ];
    }

    /**
     * Plain-language explanation of a state that is easy to misread (waiting for a
     * schedule / schedule offered again). Null when the status label says it all.
     */
    public function statusNotice(): ?string
    {
        if ($this->status === BookingStatus::Waitlisted) {
            if ($this->payment_status === BookingPaymentStatus::PendingVerification) {
                return 'Another booking currently holds this schedule. Your payment reference is kept on record. If the schedule becomes available it may be offered to you without paying again. If it does not, and you already sent a payment, the photographer will arrange your refund.';
            }

            return 'Another booking currently holds this schedule. Your reservation may be considered if the slot becomes available.';
        }

        if ($this->status === BookingStatus::Confirmed && $this->slot_offered_at !== null) {
            if ($this->payment_status === BookingPaymentStatus::Pending) {
                   return $this->slot_offer_accepted_at === null
       ? 'The schedule is available again. Accept the offer before the reservation deadline to be able to pay for it.'
       : 'The schedule is available again. You can proceed with payment before the reservation deadline.';
            }
            if ($this->payment_status === BookingPaymentStatus::PendingVerification) {
                return 'The schedule is available again. Your submitted payment is waiting for the photographer to confirm it.';
            }
        }

        return null;
    }

    /** null | open | awaiting_admin | disputed | upheld | overturned | final (old rows) */
    public function nonCompletionState(): ?string
    {
        if ($this->non_completion_review_status === 'overturned') {
            return 'overturned';
        }
        if (! $this->non_completion_reason) {
            return null;
        }
        if ($this->non_completion_review_status === 'upheld') {
            return 'upheld';
        }
        if ($this->non_completion_review_status === 'pending_admin') {
            if ($this->non_completion_disputed_at) {
                return 'disputed';
            }

            return $this->non_completion_dispute_deadline_at && $this->non_completion_dispute_deadline_at->isFuture()
                ? 'open'
                : 'awaiting_admin';
        }

        return 'final';
    }
}