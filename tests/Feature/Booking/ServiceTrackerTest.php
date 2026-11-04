<?php

namespace Tests\Feature\Booking;

use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_guest_cannot_update_service_tracker(): void
    {
        $photographer = User::factory()->photographer()->create();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(401);
    }

        public function test_photographer_cannot_manually_move_upcoming_to_event_day(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create([
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
        ]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(422);

        $this->assertSame('upcoming', $booking->fresh()->service_status->value);
    }

        public function test_service_tracker_walks_through_every_manual_stage_in_order(): void
    {
        $photographer = $this->approvedPhotographer();
        // Confirmed + fully paid: BookingObserver auto-starts the tracker at "upcoming".
        $booking = Booking::factory()->confirmed()->create([
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
        ]);
        Sanctum::actingAs($photographer);

        // Upcoming -> Event Day is automatic now: the scheduled job does it once the start time has passed.
        app(\App\Actions\Booking\RunServiceProgressTransitionsAction::class)->execute();
        $this->assertSame('event_day', $booking->fresh()->service_status->value);

        // Event Day -> Editing is only possible through "Confirm Shoot Completed".
        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")
            ->assertOk()->assertJsonPath('data.service_status', 'editing');

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'delivered',
        ])->assertOk()->assertJsonPath('data.service_status', 'delivered');

        // Delivered does NOT complete the booking on its own.
        $this->assertSame('confirmed', $booking->fresh()->status->value);
    }

    public function test_tracker_stages_cannot_be_skipped(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        // upcoming -> editing skips event_day
        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'editing',
        ])->assertStatus(422);
    }

    public function test_event_day_is_refused_before_the_scheduled_start(): void
    {
        $photographer = $this->approvedPhotographer();
        // Factory default: event is 10 days away.
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(422);

        $this->assertNotSame('event_day', $booking->fresh()->service_status?->value);
    }

    public function test_generic_tracker_cannot_move_event_day_to_editing(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::EventDay)
            ->create([
                'photographer_id' => $photographer->id,
                'event_date' => now()->subDay()->format('Y-m-d'),
            ]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'editing',
        ])->assertStatus(422);
    }

    public function test_confirm_shoot_moves_event_day_to_editing(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::EventDay)
            ->create([
                'photographer_id' => $photographer->id,
                'event_date' => now()->subDay()->format('Y-m-d'),
            ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")
            ->assertOk()->assertJsonPath('data.service_status', 'editing');
    }

    public function test_pending_no_show_report_blocks_confirm_shoot(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::EventDay)
            ->create([
                'photographer_id' => $photographer->id,
                'event_date' => now()->subDay()->format('Y-m-d'),
                'non_completion_review_status' => 'pending_admin',
            ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")
            ->assertStatus(422);

        $this->assertSame('event_day', $booking->fresh()->service_status->value);
    }

    public function test_confirm_shoot_is_refused_until_the_service_has_ended(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 12:00:00'));

        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::EventDay)
            ->create([
                'photographer_id' => $photographer->id,
                'event_date' => '2026-11-01',
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]);
        Sanctum::actingAs($photographer);

        // 12:00 - the shoot is still running.
        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")->assertStatus(422);
        $this->assertSame('event_day', $booking->fresh()->service_status->value);

        // 14:00 - the scheduled end has passed.
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 14:00:00'));
        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")
            ->assertOk()->assertJsonPath('data.service_status', 'editing');

        $this->travelBack();
    }

    public function test_multi_day_booking_cannot_be_confirmed_until_the_last_day_ends(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 14:00:00'));

        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::EventDay)
            ->create([
                'photographer_id' => $photographer->id,
                'event_date' => '2026-11-01',
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]);
        \App\Models\BookingSchedule::create([
            'booking_id' => $booking->id,
            'label' => 'Day 2',
            'event_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'duration_minutes' => 240,
            'location_type' => 'studio',
            'sort_order' => 1,
        ]);
        Sanctum::actingAs($photographer);

        // Day 1 is over but day 2 hasn't happened yet.
        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")->assertStatus(422);

        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 14:00:00'));
        $this->postJson("/api/photographer/bookings/{$booking->id}/confirm-shoot")->assertOk();

        $this->travelBack();
    }

    public function test_marking_service_completed_after_delivery_completes_the_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::Delivered)
            ->create([
                'photographer_id' => $photographer->id,
                // Ended long ago: the 48h no-show reporting window is over.
                'event_date' => now()->subDays(5)->format('Y-m-d'),
            ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/complete")->assertOk();

        $this->assertSame('completed', $booking->fresh()->status->value);
    }

    public function test_cannot_mark_completed_while_the_client_can_still_report_a_no_show(): void
    {
        $photographer = $this->approvedPhotographer();
        // Event was yesterday: end time + 48h is still in the future.
        $booking = Booking::factory()
            ->withServiceStatus(ServiceTrackerStatus::Delivered)
            ->create([
                'photographer_id' => $photographer->id,
                'event_date' => now()->subDay()->format('Y-m-d'),
            ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/complete")->assertStatus(422);

        $this->assertSame('confirmed', $booking->fresh()->status->value);
    }

    public function test_cannot_mark_completed_before_the_tracker_reaches_delivered(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/complete")->assertStatus(422);

        $this->assertSame('confirmed', $booking->fresh()->status->value);
    }

    public function test_invalid_service_status_is_rejected(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'not_a_real_stage',
        ])->assertStatus(422);
    }

    public function test_service_status_is_required(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [])
            ->assertStatus(422);
    }

    public function test_cannot_manage_tracker_on_a_pending_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create(['photographer_id' => $photographer->id]); // default status: pending
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(422);
    }

    public function test_cannot_manage_tracker_on_an_accepted_but_unconfirmed_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(422);
    }

    public function test_other_photographer_cannot_manage_the_tracker(): void
    {
        $owner = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $owner->id]);

        $other = $this->approvedPhotographer();
        Sanctum::actingAs($other);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(403);
    }

    public function test_unapproved_photographer_cannot_manage_the_tracker(): void
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($photographer)->pendingReview()->create();
        $booking = Booking::factory()->confirmed()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(403);
    }

    public function test_client_cannot_manage_the_service_tracker(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->patchJson("/api/photographer/bookings/{$booking->id}/service-tracker", [
            'service_status' => 'event_day',
        ])->assertStatus(403);
    }
}