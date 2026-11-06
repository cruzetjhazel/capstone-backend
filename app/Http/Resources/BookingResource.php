<?php

namespace App\Http\Resources;

use App\Http\Resources\BookingExtensionResource;
use App\Enums\BookingLocationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Contact details reach the studio only after it accepts the request (same rule as the client side).
            $showContact = $request->user()?->id === $this->photographer_id
                && $this->status !== \App\Enums\BookingStatus::Pending;

            return [
            'id' => $this->id,
            'is_manual' => $this->is_manual,
            'client' => [
                'id' => $this->client?->id,
                'name' => $this->client?->name ?? $this->walkInClient?->name ?? 'Client',
                // email_verified is only a flag. The real email and phone go only to this booking's own photographer.
                'email_verified' => $this->client?->email_verified_at !== null,
                'is_walk_in' => $this->walk_in_client_id !== null,
                'walk_in_client_id' => $this->walk_in_client_id,
                'phone' => $this->walkInClient?->phone
    ?? ($showContact ? $this->client?->phone_number : null),
'email' => $this->walkInClient?->email ?? ($showContact ? $this->client?->email : null),
            ],
            'photographer' => [
                'id' => $this->photographer->id,
                'name' => $this->photographer->name,
                'avatar' => $this->photographer->photographerProfile?->profile_photo_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->photographer->photographerProfile->profile_photo_path)
                    : null,
            ],
            'is_custom_package' => $this->is_custom_package,
            'package_snapshot' => $this->package_snapshot,
            'custom_package_snapshot' => $this->custom_package_snapshot,
            'custom_hours' => $this->custom_hours,
            'add_ons_snapshot' => $this->add_ons_snapshot,
            'event_type' => $this->event_type,
            'custom_event_type' => $this->custom_event_type,
            'event_date' => $this->event_date->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
            'duration_confirmed' => $this->end_time !== null,
            'location_type' => $this->location_type->value,
            'province' => $this->province ? ['id' => $this->province->id, 'name' => $this->province->name] : null,
            'city_municipality' => $this->cityMunicipality ? ['id' => $this->cityMunicipality->id, 'name' => $this->cityMunicipality->name] : null,
            'barangay' => $this->barangay ? ['id' => $this->barangay->id, 'name' => $this->barangay->name] : null,
            'event_address' => $this->event_address,
            'guest_count' => $this->guest_count,
            'special_requests' => $this->special_requests,
            'coverage_area_notice' => $this->location_type !== BookingLocationType::Studio,
            'subtotal' => $this->subtotal,
            'platform_fee' => $this->platform_fee,
            'total_price' => $this->total_price,
            'status' => $this->status->value,
            'hold_expires_at' => $this->hold_expires_at,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by' => $this->cancelled_by,
            // A request the photographer declined is stored as Cancelled + a rejection reason.
            'is_declined' => $this->status === \App\Enums\BookingStatus::Cancelled && $this->rejection_reason !== null,
            'buffer_minutes' => $this->bufferMinutes(),
            'cancellation_requested_at' => $this->cancellation_requested_at,
            'cancellation_decision' => $this->cancellation_decision?->value,
            'cancellation_decided_at' => $this->cancellation_decided_at,
            'non_completion_reason' => $this->non_completion_reason,
            'non_completion_reported_at' => $this->non_completion_reported_at,
            'non_completion_reported_by' => $this->non_completion_reported_by,
            'non_completion_state' => $this->nonCompletionState(),
            'non_completion_dispute_deadline_at' => $this->non_completion_dispute_deadline_at,
            'non_completion_dispute_reason' => $this->non_completion_dispute_reason,
            'non_completion_admin_notes' => $this->non_completion_admin_notes,
            'is_reviewable' => $this->isReviewable(),
            // Same rules the backend actions enforce (see Booking::clientActions()).
            'allowed_actions' => $this->clientActions(),
            'completion_blocked_until' => $this->completionBlockedUntil()?->toISOString(),
            'reschedule_history' => $this->reschedules()->oldest('id')->get()->map(fn ($r) => [
                'type' => $r->type,
                'original_event_date' => $r->original_event_date?->format('Y-m-d'),
                'original_start_time' => $r->original_start_time,
                'original_end_time' => $r->original_end_time,
                'new_event_date' => $r->new_event_date?->format('Y-m-d'),
                'new_start_time' => $r->new_start_time,
                'new_end_time' => $r->new_end_time,
                'reason' => $r->reason,
                'requested_by' => $r->requested_by,
                'approved_by' => $r->approved_by,
                'decided_at' => $r->decided_at,
            ])->values(),
            // What was asked, decided and why: reschedules, modifications and cancellations, from the activity log.
            'history' => \App\Models\ActivityLog::query()
                ->where('subject_type', \App\Models\Booking::class)
                ->where('subject_id', $this->id)
                ->where(fn ($q) => $q->where('action', 'like', 'booking.reschedule_%')
                    ->orWhere('action', 'like', 'booking.modification_%')
                    ->orWhere('action', 'like', 'booking.cancellation_%')
                    ->orWhere('action', 'booking.cancelled'))
                ->oldest('created_at')->get()
                ->map(fn ($log) => [
                    'action' => $log->action,
                    'description' => $log->description,
                    'date' => $log->created_at?->toISOString(),
                ])->values(),
            'reschedule_request' => $this->reschedule_requested_at ? [
                'requested_event_date' => $this->requested_event_date?->format('Y-m-d'),
                'requested_start_time' => $this->requested_start_time,
                'type' => $this->reschedule_type ?? 'standard',
                'reason' => $this->reschedule_reason,
                'requested_at' => $this->reschedule_requested_at,
                'decision' => $this->reschedule_decision?->value,
                'decided_at' => $this->reschedule_decided_at,
            ] : null,
            'modification_request' => $this->modification_requested_at ? [
                'type' => $this->modification_type,
                'reason' => $this->modification_reason,
                'requested_at' => $this->modification_requested_at,
                'changes' => $this->modification_changes,
                'decision' => $this->modification_decision?->value,
                'decided_at' => $this->modification_decided_at,
            ] : null,
            'payment_plan' => $this->payment_plan?->value,
            'payment_status' => $this->payment_status?->value,
            'amount_paid' => $this->totalPaid(),
            'remaining_balance' => $this->remainingBalance(),
            'payment_actions' => [
                'can_submit_online_payment' => $this->status?->value === 'confirmed'
                        && $this->payment_status?->value === 'pending'
    && ! $this->isAwaitingOfferAcceptance(),
                    'can_record_onsite_payment' => $this->isEligibleForOnsitePayment(),
    'can_accept_slot_offer' => $this->isAwaitingOfferAcceptance(),
    'can_decline_slot_offer' => $this->status?->value === 'confirmed'
        && $this->slot_offered_at !== null
        && $this->payment_status?->value === 'pending',
            ],
            // Plain-language explanation for waitlisted / schedule-offered bookings (null otherwise).
            'status_notice' => $this->statusNotice(),
            'is_waitlisted' => $this->status === \App\Enums\BookingStatus::Waitlisted,
                   'slot_offered_at' => $this->slot_offered_at,
   ...\App\Models\BookingBudgetRequest::summaryFor($this->resource),
    'slot_offer_accepted_at' => $this->slot_offer_accepted_at,
    'awaiting_offer_acceptance' => $this->isAwaitingOfferAcceptance(),
    'slot_offer_deadline' => $this->isAwaitingOfferAcceptance() ? $this->hold_expires_at : null,
            'service_status' => $this->service_status?->value,
            'service_status_updated_at' => $this->service_status_updated_at,
            'has_reviewed' => $this->review()->exists(),
            // Sliding-hours custom bookings only — see Booking::
            // isEligibleForExtensionRequest(). extensions is the full
            // history (pending/approved/declined) so the UI can show past
            // decisions, not just the current pending one.
            'extension_eligible' => $this->isEligibleForExtensionRequest(),
            'has_pending_extension' => $this->hasPendingExtensionRequest(),
            'approved_extension_charge' => $this->approvedExtensionCharge(),
            'extensions' => BookingExtensionResource::collection($this->extensions()->latest('requested_at')->get()),
            // Schedule 1 (this booking's own date/time) + every additional
            // BookingSchedule row, in one ordered list — see
            // Booking::allSchedules(). Multi-date bookings (e.g. Prenup +
            // Wedding on separate days) show up here as separate entries
            // rather than one continuous date range.
            'schedules' => BookingScheduleResource::collection($this->allSchedules()),
            'created_at' => $this->created_at,
        ];
    }
}