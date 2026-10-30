<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    use ApiResponses;

    private const STATUS_LABELS = [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'confirmed' => 'Confirmed',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
        'expired' => 'Expired',
        'not_completed' => 'Not Completed',
        'no_show' => 'Not Completed',
    ];

    private const PAYMENT_STATUS_LABELS = [
        'pending' => 'Pending',
        'pending_verification' => 'Pending Verification',
        'partially_paid' => 'Partially Paid',
        'fully_paid' => 'Fully Paid',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    private const PAYMENT_PLAN_LABELS = [
        'half' => 'Half Payment',
        'full' => 'Full Payment',
    ];

    private const TRACKER_STEP_LABELS = [
        'upcoming' => 'Upcoming',
        'event_day' => 'Event Day',
        'in_progress' => 'In Progress',
        'editing' => 'Editing',
        'delivered' => 'Delivered',
        'photo_editing' => 'Photo Editing',
        'ready_for_release' => 'Ready for Release',
        'completed' => 'Completed',
    ];

    /**
     * GET /admin/bookings
     * Full, filterable, paginated booking list for the admin bookings page.
     * Also returns platform-wide status counts (unaffected by the current
     * filters) for the summary stat cards.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        app(\App\Actions\Booking\ExpireStaleBookingHoldsAction::class)->executeThrottled();

        $request->validate([
            'status' => ['sometimes', 'nullable', 'string'],
            'payment_status' => ['sometimes', 'nullable', 'string'],
            'photographer_id' => ['sometimes', 'nullable', 'integer'],
            'date_filter' => ['sometimes', 'nullable', 'in:today,last7days,thismonth'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $query = Booking::with([
            'client:id,name,email,phone_number',
            'photographer:id,name',
            'photographer.photographerApplication:id,user_id,photographer_type',
            'package:id,name',
        ]);

        if ($request->filled('status')) {
            $statusFilter = strtolower($request->string('status')->toString());
            if ($statusFilter === 'declined') {
                $query->where('status', 'cancelled')->whereNotNull('rejection_reason');
                            } elseif ($statusFilter === 'not_completed') {
                $query->where('status', 'no_show');
            } elseif ($statusFilter === 'no_show_review') {
                $query->where('non_completion_review_status', 'pending_admin');
                } elseif ($statusFilter === 'cancelled') {
                $query->where('status', 'cancelled')->whereNull('rejection_reason')
                    ->where(fn ($q) => $q->whereNull('non_completion_review_status')->orWhere('non_completion_review_status', '!=', 'upheld'));
            } else {
                $query->where('status', $statusFilter);
            }
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', strtolower($request->string('payment_status')));
        }

        if ($request->filled('photographer_id')) {
            $query->where('photographer_id', $request->integer('photographer_id'));
        }

        if ($request->filled('date_filter')) {
            match ($request->string('date_filter')->toString()) {
                'today' => $query->whereDate('created_at', now()->toDateString()),
                'last7days' => $query->where('created_at', '>=', now()->subDays(7)),
                'thismonth' => $query->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month),
                default => null,
            };
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $numericId = (int) preg_replace('/\D/', '', $search) ?: null;

            $query->where(function ($q) use ($search, $numericId) {
                $q->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('photographer', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                    ->orWhere('event_type', 'like', "%{$search}%")
                    ->orWhere('custom_event_type', 'like', "%{$search}%");

                if ($numericId) {
                    $q->orWhere('id', $numericId);
                }
            });
        }

        $bookings = $query->latest()->paginate($request->integer('per_page', 10));
        $bookings->getCollection()->transform(fn (Booking $b) => $this->mapBooking($b));

        $statusCounts = Booking::query()->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status');

        return $this->success([
            'bookings' => $bookings,
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'confirmed' => (int) ($statusCounts['confirmed'] ?? 0),
                'completed' => (int) ($statusCounts['completed'] ?? 0),
                'cancelled_or_rejected' => (int) (($statusCounts['cancelled'] ?? 0) + ($statusCounts['rejected'] ?? 0)),
                'no_show' => (int) ($statusCounts['no_show'] ?? 0),
            ],
        ]);
    }

    /**
     * GET /admin/bookings/{booking}
     * Full detail for one booking, including its activity-log history.
     */
    public function show(Request $request, Booking $booking)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $booking->load([
            'client:id,name,email,phone_number',
            'photographer:id,name',
            'photographer.photographerApplication:id,user_id,photographer_type',
            'package:id,name',
        ]);

        $history = ActivityLog::with('causer')
            ->where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)
            ->oldest('created_at')
            ->get()
            ->map(fn (ActivityLog $log) => [
                'date' => $log->created_at?->toISOString(),
                'action' => $log->description ?? $log->action,
            ])
            ->values();

        return $this->success([
            ...$this->mapBooking($booking),
            'history' => $history,
        ]);
    }

    /**
     * POST /admin/bookings/{booking}/cancel
     * Admin override — force-cancels a booking regardless of its current
     * status/payment state. Marks payment_status as Cancelled too
     * (record-keeping only; no refund is processed automatically).
     */
    public function cancel(Request $request, Booking $booking)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        abort_if(
            in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::Expired, BookingStatus::NoShow], true),
            422,
            'This booking cannot be cancelled from its current status.'
        );

        $booking->update([
            'status' => BookingStatus::Cancelled,
            'payment_status' => BookingPaymentStatus::Cancelled,
            'cancellation_reason' => $request->input('reason', 'Cancelled by administrator.'),
            'cancelled_by' => 'admin',
        ]);

        ActivityLog::create([
            'causer_id' => $request->user()->id,
            'subject_type' => Booking::class,
            'subject_id' => $booking->id,
            'action' => 'booking.admin_cancelled',
            'description' => 'Booking force-cancelled by administrator.'.($request->filled('reason') ? ' Reason: '.$request->input('reason') : ''),
        ]);

        $fresh = $booking->fresh(['client', 'photographer', 'package']);
        $fresh->client?->notify(new \App\Notifications\Booking\BookingCancelledNotification($fresh));
        $fresh->photographer?->notify(new \App\Notifications\Booking\BookingCancelledNotification($fresh));

        return $this->success($this->mapBooking($fresh), 'Booking cancelled.');
    }

    private function mapBooking(Booking $b): array
    {
        $statusValue = $b->status?->value ?? (string) $b->status;
        // A request the photographer declined is stored as cancelled + a rejection reason.
        if ($statusValue === 'cancelled' && $b->rejection_reason !== null) {
            $statusValue = 'declined';
        }
        $paymentStatusValue = $b->payment_status?->value ?? (string) $b->payment_status;
        $planValue = $b->payment_plan?->value ?? (string) $b->payment_plan;

        return [
            'id' => 'BK-'.str_pad((string) $b->id, 4, '0', STR_PAD_LEFT),
            'raw_id' => $b->id,
            'client' => $b->client?->name ?? $b->walkInClient?->name ?? 'Unknown client',
            'clientEmail' => $b->client?->email,
            'clientPhone' => $b->client?->phone_number,
            'photographer' => $b->photographer?->name ?? 'Unknown professional',
            'photographerId' => $b->photographer_id,
            'professionalType' => $b->photographer?->photographerApplication?->photographer_type?->value === 'studio' ? 'Studio' : 'Freelancer',
            'event' => $b->custom_event_type ?? (string) $b->event_type,
            'eventLocation' => $b->location_type?->value ?? (string) $b->location_type,
            'eventAddress' => $b->event_address,
            'guests' => $b->guest_count,
            'bookingDate' => $b->created_at?->toISOString(),
            'eventDate' => $b->event_date?->format('Y-m-d'),
            'startingTime' => $b->start_time,
            'expectedEndTime' => $b->end_time,
            'paymentStatus' => self::PAYMENT_STATUS_LABELS[$paymentStatusValue] ?? $paymentStatusValue,
            'paymentPlan' => self::PAYMENT_PLAN_LABELS[$planValue] ?? $planValue,
            'status' => self::STATUS_LABELS[$statusValue] ?? $statusValue,
            'package' => $b->package?->name ?? ($b->is_custom_package ? 'Custom Package' : '—'),
            'addons' => collect($b->add_ons_snapshot ?? [])->pluck('name')->filter()->values(),
            'totalAmount' => (float) $b->total_price,
            'amountPaid' => $b->totalPaid(),
            'balance' => $b->remainingBalance(),
            'clientNotes' => $b->special_requests,
            'cancellationReason' => $b->cancellation_reason,
                        'noShow' => $b->non_completion_reason ? [
                'reason' => $b->non_completion_reason->value,
                'state' => $b->nonCompletionState(),
                'reportedBy' => $b->non_completion_reported_by === $b->client_id ? 'client' : 'photographer',
                'reportedAt' => $b->non_completion_reported_at?->toISOString(),
                'statement' => $b->cancellation_reason,
                'deadline' => $b->non_completion_dispute_deadline_at?->toISOString(),
                'disputedAt' => $b->non_completion_disputed_at?->toISOString(),
                'disputeReason' => $b->non_completion_dispute_reason,
                'adminNotes' => $b->non_completion_admin_notes,
                'canDecide' => $b->non_completion_review_status === 'pending_admin',
            ] : null,
            // TODO: pull from the latest Payment record once Payment.php is available —
            // left null rather than guessed so the frontend can render "—" honestly.
            'invoiceId' => null,
            'paymentMethod' => null,
            'paymentDate' => null,
            'serviceTracking' => $this->buildTracker($b),
        ];
    }

    private function buildTracker(Booking $b): array
    {
        if (! $b->canManageServiceTracker()) {
            return [];
        }

        $steps = ServiceTrackerStatus::ordered();
        $currentIndex = array_search($b->service_status, $steps, true);

        return collect($steps)->map(function (ServiceTrackerStatus $step, int $i) use ($currentIndex, $b) {
            $status = $currentIndex === false ? 'Pending' : ($i < $currentIndex ? 'Completed' : ($i === $currentIndex ? 'In Progress' : 'Pending'));

            return [
                'label' => self::TRACKER_STEP_LABELS[$step->value] ?? ucfirst(str_replace('_', ' ', $step->value)),
                'status' => $status,
                'date' => $i === $currentIndex ? $b->service_status_updated_at?->toISOString() : null,
            ];
        })->values()->all();
    }

        public function resolveNonCompletion(Request $request, Booking $booking, \App\Actions\Booking\ResolveNonCompletionDisputeAction $action)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $data = $request->validate([
            'decision' => ['required', 'in:upheld,overturned'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = $action->execute($booking, $request->user(), $data['decision'], $data['notes'] ?? null);

        return $this->success(new \App\Http\Resources\BookingResource($booking), 'Dispute resolved.');
    }
}