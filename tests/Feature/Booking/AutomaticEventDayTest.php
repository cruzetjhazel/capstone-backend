<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\RunServiceProgressTransitionsAction;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\BookingSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticEventDayTest extends TestCase
{
    use RefreshDatabase;

    private function manila(string $dateTime): Carbon
    {
        return Carbon::parse($dateTime, 'Asia/Manila');
    }

    private function upcomingBooking(array $attributes = []): Booking
    {
        $booking = Booking::factory()->confirmed()->create(array_merge([
            'event_date' => '2026-11-10',
            'start_time' => '09:00',
            'end_time' => '13:00',
        ], $attributes));

        // Confirmed + paid: BookingObserver has started the tracker at "upcoming".
        $this->assertSame(ServiceTrackerStatus::Upcoming, $booking->fresh()->service_status);

        return $booking;
    }

    private function runTransitions(): int
    {
        return app(RunServiceProgressTransitionsAction::class)->execute()['moved_to_event_day'];
    }

    public function test_moves_to_event_day_exactly_at_the_scheduled_start_in_manila_time(): void
    {
        $booking = $this->upcomingBooking();

        $this->travelTo($this->manila('2026-11-10 08:59:00'));
        $this->assertSame(0, $this->runTransitions());
        $this->assertSame('upcoming', $booking->fresh()->service_status->value);

        $this->travelTo($this->manila('2026-11-10 09:00:00'));
        $this->assertSame(1, $this->runTransitions());

        $fresh = $booking->fresh();
        $this->assertSame('event_day', $fresh->service_status->value);
        $this->assertNotNull($fresh->service_status_updated_at);
        $this->assertSame('confirmed', $fresh->status->value);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $booking->client_id,
            'type' => \App\Notifications\Booking\ServiceTrackerUpdatedNotification::class,
        ]);

        $this->travelBack();
    }

    public function test_a_second_run_does_nothing(): void
    {
        $this->upcomingBooking();

        $this->travelTo($this->manila('2026-11-10 10:00:00'));
        $this->assertSame(1, $this->runTransitions());
        $this->assertSame(0, $this->runTransitions());

        $this->travelBack();
    }

    public function test_does_not_move_while_a_request_or_report_is_pending(): void
    {
        $cases = [
            'postponement' => [
                'reschedule_type' => 'weather',
                'reschedule_requested_at' => now(),
                'reschedule_decision' => null,
                'requested_event_date' => '2026-11-20',
                'requested_start_time' => '09:00',
            ],
            'cancellation' => ['cancellation_requested_at' => now(), 'cancellation_decision' => null],
            'modification' => ['modification_requested_at' => now(), 'modification_decision' => null],
            'no-show report' => ['non_completion_review_status' => 'pending_admin'],
        ];

        $bookings = [];
        foreach ($cases as $label => $attributes) {
            $booking = $this->upcomingBooking();
            $booking->forceFill($attributes)->saveQuietly();
            $bookings[$label] = $booking;
        }

        $this->travelTo($this->manila('2026-11-10 10:00:00'));
        $this->assertSame(0, $this->runTransitions());

        foreach ($bookings as $label => $booking) {
            $this->assertSame('upcoming', $booking->fresh()->service_status->value, "{$label} should block Event Day");
        }

        $this->travelBack();
    }

    public function test_does_not_move_a_booking_that_is_no_longer_confirmed(): void
    {
        $booking = $this->upcomingBooking();
        $booking->forceFill(['status' => BookingStatus::Cancelled])->saveQuietly();

        $this->travelTo($this->manila('2026-11-10 10:00:00'));
        $this->assertSame(0, $this->runTransitions());
        $this->assertSame('upcoming', $booking->fresh()->service_status->value);

        $this->travelBack();
    }

    public function test_a_multi_day_booking_moves_when_its_first_session_starts(): void
    {
        $booking = $this->upcomingBooking();
        BookingSchedule::create([
            'booking_id' => $booking->id,
            'label' => 'Day 2',
            'event_date' => '2026-11-11',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'duration_minutes' => 240,
            'location_type' => 'studio',
            'sort_order' => 1,
        ]);

        $this->travelTo($this->manila('2026-11-10 09:00:00'));
        $this->assertSame(1, $this->runTransitions());
        $this->assertSame('event_day', $booking->fresh()->service_status->value);

        $this->travelBack();
    }
}