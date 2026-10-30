<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalkInClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a booking the photographer took OUTSIDE the platform, for one of
 * their walk-in clients. It skips the request/approval/payment/expiry flow:
 * the booking is created already Confirmed (or Completed for a past event),
 * and it holds the photographer's calendar like any paid booking.
 */
class CreateManualBookingAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(User $photographer, array $data): Booking
    {
        $total = round((float) $data['total_price'], 2);
        $paid = round((float) ($data['amount_paid'] ?? 0), 2);
        $completed = (bool) ($data['mark_completed'] ?? false);
        $endTime = $data['end_time'] ?? null;
        $duration = $endTime
            ? (int) round(abs(Carbon::parse($data['start_time'])->diffInMinutes(Carbon::parse($endTime))))
            : null;

        $booking = DB::transaction(function () use ($photographer, $data, $total, $paid, $completed, $endTime, $duration) {
            // Same row lock CreateBookingAction uses, so two writes can't slip past the overlap check together.
            User::where('id', $photographer->id)->lockForUpdate()->firstOrFail();

            $walkIn = isset($data['walk_in_client_id'])
                ? WalkInClient::where('photographer_id', $photographer->id)->findOrFail($data['walk_in_client_id'])
                : WalkInClient::create([
                    ...$data['new_client'],
                    'photographer_id' => $photographer->id,
                    'status' => 'inactive',
                ]);

            $attributes = [
                'client_id' => null,
                'walk_in_client_id' => $walkIn->id,
                'is_manual' => true,
                'manual_amount_paid' => $paid,
                'photographer_id' => $photographer->id,
                'package_id' => null,
                'is_custom_package' => false,
                'package_snapshot' => [
                    'name' => trim($data['package_name'] ?? '') ?: 'Booked outside the platform',
                    'price' => (string) $total,
                    'duration_minutes' => $duration,
                    'schedule_mode' => $endTime ? 'timed' : 'open',
                    'buffer_minutes' => 0,
                    'allows_multiple_sessions' => false,
                    'max_sessions' => null,
                    'manual' => true,
                ],
                'event_type' => $data['event_type'],
                'custom_event_type' => $data['custom_event_type'] ?? null,
                'event_date' => $data['event_date'],
                'start_time' => $data['start_time'],
                'end_time' => $endTime,
                'duration_minutes' => $duration,
                'location_type' => $data['location_type'],
                'event_address' => $data['event_address'] ?? null,
                'guest_count' => $data['guest_count'] ?? null,
                'special_requests' => $data['notes'] ?? null,
                'subtotal' => $total,
                'total_price' => $total,
                'status' => $completed ? BookingStatus::Completed : BookingStatus::Confirmed,
                'payment_status' => $this->paymentStatusFor($total, $paid),
                'service_status' => $completed ? ServiceTrackerStatus::Completed : ServiceTrackerStatus::Upcoming,
                'service_status_updated_at' => now(),
            ];

            // A booking that is still ahead must not overlap anything that already holds the calendar
            // (a paid platform booking or another manual one). Past records are history, so they are not checked.
            // A booking with no end time reserves no window, so it cannot be range-checked.
            if (! $completed) {
                $conflict = (new Booking($attributes))
                    ->overlappingBookings([BookingStatus::Confirmed])
                    ->first(fn (Booking $other) => $other->is_manual
                        || in_array($other->payment_status, [BookingPaymentStatus::PartiallyPaid, BookingPaymentStatus::FullyPaid], true));

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'start_time' => ['This time overlaps another confirmed booking on your calendar (booking #'.$conflict->id.').'],
                    ]);
                }
            }

            $created = Booking::create($attributes);

            if ($paid > 0) {
                Payment::create([
                    'booking_id' => $created->id,
                    'client_id' => null,
                    'photographer_id' => $photographer->id,
                    'type' => PaymentType::Onsite,
                    'method' => 'cash',
                    'plan' => null,
                    'amount' => $paid,
                    'reference_number' => null,
                    'payment_date' => now()->toDateString(),
                    'notes' => 'Paid when the booking was recorded',
                ]);
            }

            return $created;
        });

        $booking->load('walkInClient');

        $this->activityLogger->execute(
            causer: $photographer,
            subject: $booking,
            action: 'booking.manual_created',
            description: "Recorded booking #{$booking->id} made outside the platform for {$booking->walkInClient->name}",
        );

        return $booking;
    }

    private function paymentStatusFor(float $total, float $paid): BookingPaymentStatus
    {
        if ($paid <= 0) {
            return BookingPaymentStatus::Pending;
        }

        return $paid >= $total ? BookingPaymentStatus::FullyPaid : BookingPaymentStatus::PartiallyPaid;
    }
}