<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\ExpireStaleBookingHoldsAction;
use App\Enums\BookingNonCompletionReason;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('bookings:expire-stale:throttle');
    }

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    protected function windowOn(User $photographer, string $date): void
    {
        AvailabilityWindow::factory()->for($photographer)->create([
            'date' => $date,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
    }

    protected function day(int $daysAhead): string
    {
        return now()->addDays($daysAhead)->format('Y-m-d');
    }

    /** Asserts the exact message appears anywhere in the JSON error body. */
    protected function assertErrorText($response, string $text): void
    {
        $this->assertStringContainsString(
            $text,
            json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function notified(User $user): int
    {
        return $user->notifications()->count();
    }

    // ---------------------------------------------------------------- BKG-20 / BKG-26 modification

    public function test_client_can_request_a_modification_with_a_reason(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $this->day(10), 'guest_count' => 20,
        ]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 50],
            'reason' => 'More guests are coming',
        ])->assertOk()->assertJsonPath('message', 'Modification request submitted.');

        $fresh = $booking->fresh();
        $this->assertNotNull($fresh->modification_requested_at);
        $this->assertSame('More guests are coming', $fresh->modification_reason);
        $this->assertSame(['guest_count' => 50], $fresh->modification_changes);
        $this->assertSame(20, (int) $fresh->guest_count); // not applied until approved
        $this->assertGreaterThan(0, $this->notified($photographer));
    }

    public function test_modification_requires_a_reason(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id, 'event_date' => $this->day(10)]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 50],
        ])->assertStatus(422);
    }

    public function test_modification_is_rejected_less_than_7_days_before_the_event(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id, 'event_date' => $this->day(3)]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 50], 'reason' => 'Late change',
        ])->assertStatus(422);

        $this->assertErrorText($response, 'Modification requests must be made at least 7 days before the event.');
        $this->assertNull($booking->fresh()->modification_requested_at);
    }

    public function test_modification_with_no_real_change_is_rejected(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'event_date' => $this->day(10), 'guest_count' => 20,
        ]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 20], 'reason' => 'Same',
        ])->assertStatus(422);

        $this->assertErrorText($response, 'Nothing to update — what you entered is the same as the current booking.');
    }

    public function test_another_client_cannot_request_a_modification(): void
    {
        $booking = Booking::factory()->accepted()->create(['event_date' => $this->day(10)]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 50], 'reason' => 'x',
        ])->assertStatus(403);
    }

    public function test_photographer_approving_a_modification_applies_the_changes(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $this->day(10), 'guest_count' => 20,
        ]);
        $booking->update([
            'modification_type' => 'details', 'modification_reason' => 'More guests',
            'modification_changes' => ['guest_count' => 50], 'modification_requested_at' => now(),
        ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/modification/approve")
            ->assertOk()->assertJsonPath('message', 'Modification approved.');

        $fresh = $booking->fresh();
        $this->assertSame(50, (int) $fresh->guest_count);
        $this->assertSame('approved', $fresh->modification_decision->value);
        $this->assertGreaterThan(0, $this->notified($client));
    }

    public function test_photographer_rejecting_a_modification_keeps_the_details(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $this->day(10), 'guest_count' => 20,
        ]);
        $booking->update([
            'modification_type' => 'details', 'modification_reason' => 'More guests',
            'modification_changes' => ['guest_count' => 50], 'modification_requested_at' => now(),
        ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/modification/reject")
            ->assertOk()->assertJsonPath('message', 'Modification request declined.');

        $this->assertSame(20, (int) $booking->fresh()->guest_count);
        $this->assertSame('rejected', $booking->fresh()->modification_decision->value);
    }

    public function test_cannot_decide_a_modification_that_was_not_requested(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/modification/approve")->assertStatus(422);
        $this->assertErrorText($response, 'There is no pending modification request for this booking.');
    }

    // ---------------------------------------------------------------- BKG-19 / BKG-26 / BST-07 / BST-08 reschedule

    public function test_client_can_request_a_reschedule_to_an_available_slot(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $newDate = $this->day(20);
        $this->windowOn($photographer, $newDate);
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id, 'event_date' => $this->day(10),
        ]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/reschedule", [
            'event_date' => $newDate, 'start_time' => '09:00', 'reason' => 'Venue changed',
        ])->assertOk()->assertJsonPath('message', 'Reschedule requested.');

        $fresh = $booking->fresh();
        $this->assertNotNull($fresh->reschedule_requested_at);
        $this->assertSame($newDate, $fresh->requested_event_date->format('Y-m-d'));
        $this->assertSame($this->day(10), $fresh->event_date->format('Y-m-d')); // unchanged until approved
        $this->assertGreaterThan(0, $this->notified($photographer));
    }

    public function test_reschedule_is_rejected_less_than_7_days_before_the_event(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id, 'event_date' => $this->day(3)]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/reschedule", [
            'event_date' => $this->day(20), 'start_time' => '09:00', 'reason' => 'Late',
        ])->assertStatus(422);

        $this->assertErrorText($response, 'Reschedule requests must be made at least 7 days before the event.');
        $this->assertNull($booking->fresh()->reschedule_requested_at);
    }

    public function test_reschedule_to_the_same_date_and_time_is_rejected(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'event_date' => $this->day(10), 'start_time' => '09:00',
        ]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/reschedule", [
            'event_date' => $this->day(10), 'start_time' => '09:00', 'reason' => 'Same',
        ])->assertStatus(422);

        $this->assertErrorText($response, 'That is already the current date and time of this booking.');
    }

    public function test_reschedule_to_a_slot_that_is_not_offered_is_rejected(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        AvailabilityWindow::factory()->for($photographer)->create([
            'date' => $this->day(20), 'start_time' => '13:00', 'end_time' => '18:00',
        ]);
        // Usual hours must exist, otherwise the day counts as open 24h and the one-off window only adds to it.
        \App\Models\BookingHour::create([
            'user_id' => $photographer->id,
            'day_of_week' => now()->addDays(20)->dayOfWeek,
            'start_time' => '13:00',
            'end_time' => '18:00',
        ]);
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id, 'event_date' => $this->day(10),
        ]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/reschedule", [
            'event_date' => $this->day(20), 'start_time' => '09:00', 'reason' => 'x',
        ])->assertStatus(422);

        $this->assertErrorText($response, 'This date and time is not available for booking.');
    }

    public function test_photographer_approving_a_reschedule_moves_the_booking(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $newDate = $this->day(20);
        $this->windowOn($photographer, $newDate);
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id, 'event_date' => $this->day(10),
        ]);
        $booking->update([
            'requested_event_date' => $newDate, 'requested_start_time' => '09:00',
            'reschedule_reason' => 'Venue changed', 'reschedule_type' => 'standard',
            'reschedule_requested_at' => now(),
        ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/approve")
            ->assertOk()->assertJsonPath('message', 'Reschedule approved.');

        $fresh = $booking->fresh();
        $this->assertSame($newDate, $fresh->event_date->format('Y-m-d'));
        $this->assertSame('09:00', substr((string) $fresh->start_time, 0, 5));
        $this->assertSame('approved', $fresh->reschedule_decision->value);
        $this->assertSame(1, $fresh->reschedules()->count());
        $this->assertGreaterThan(0, $this->notified($client));
    }

    public function test_approving_a_reschedule_fails_when_the_slot_is_gone(): void
    {
        $photographer = $this->approvedPhotographer();
        AvailabilityWindow::factory()->for($photographer)->create([
            'date' => $this->day(20), 'start_time' => '13:00', 'end_time' => '18:00',
        ]);
        // Usual hours must exist, otherwise the day counts as open 24h and the one-off window only adds to it.
        \App\Models\BookingHour::create([
            'user_id' => $photographer->id,
            'day_of_week' => now()->addDays(20)->dayOfWeek,
            'start_time' => '13:00',
            'end_time' => '18:00',
        ]);
        $booking = Booking::factory()->accepted()->create([
            'photographer_id' => $photographer->id, 'event_date' => $this->day(10),
        ]);
        $booking->update([
            'requested_event_date' => $this->day(20), 'requested_start_time' => '09:00',
            'reschedule_reason' => 'x', 'reschedule_type' => 'standard', 'reschedule_requested_at' => now(),
        ]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/approve")->assertStatus(422);

        $this->assertErrorText($response, 'That slot is no longer available — ask the client to pick another time.');
        $this->assertSame($this->day(10), $booking->fresh()->event_date->format('Y-m-d'));
    }

    public function test_photographer_rejecting_a_reschedule_keeps_the_original_slot(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $this->day(10), 'start_time' => '09:00',
        ]);
        $booking->update([
            'requested_event_date' => $this->day(20), 'requested_start_time' => '10:00',
            'reschedule_reason' => 'x', 'reschedule_type' => 'standard', 'reschedule_requested_at' => now(),
        ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/reject")
            ->assertOk()->assertJsonPath('message', 'Reschedule request declined.');

        $fresh = $booking->fresh();
        $this->assertSame($this->day(10), $fresh->event_date->format('Y-m-d'));
        $this->assertSame('09:00', substr((string) $fresh->start_time, 0, 5));
        $this->assertSame('rejected', $fresh->reschedule_decision->value);
        $this->assertGreaterThan(0, $this->notified($client));
    }

    public function test_cannot_decide_a_reschedule_that_was_not_requested(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/approve")->assertStatus(422);
        $this->assertErrorText($response, 'There is no pending reschedule request for this booking.');
    }

    public function test_client_and_other_photographer_cannot_decide_a_reschedule(): void
    {
        $owner = $this->approvedPhotographer();
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id, 'photographer_id' => $owner->id]);
        $booking->update([
            'requested_event_date' => $this->day(20), 'requested_start_time' => '09:00',
            'reschedule_reason' => 'x', 'reschedule_type' => 'standard', 'reschedule_requested_at' => now(),
        ]);

        Sanctum::actingAs($this->approvedPhotographer());
        $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/approve")->assertStatus(403);

        Sanctum::actingAs($client);
        $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/approve")->assertStatus(403);
    }

    // ---------------------------------------------------------------- BKG-21 / BST-05 / BST-06 cancellation

    public function test_cancelling_an_unpaid_booking_cancels_it_immediately(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->accepted()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'Change of plans'])
            ->assertOk()->assertJsonPath('message', 'Booking cancelled.');

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancelling_a_paid_booking_is_only_a_request(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'Change of plans'])
            ->assertOk()->assertJsonPath('message', 'Cancellation requested. The photographer will review it.');

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $fresh->status);
        $this->assertNotNull($fresh->cancellation_requested_at);
        $this->assertNull($fresh->cancellation_decision);
        $this->assertGreaterThan(0, $this->notified($photographer));
    }

    public function test_cannot_cancel_once_the_service_has_started(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->withServiceStatus(ServiceTrackerStatus::EventDay)->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'x'])->assertStatus(422);
        $this->assertErrorText($response, 'This booking can no longer be cancelled — the service has already started.');
    }

    public function test_a_second_cancellation_request_is_refused(): void
    {
        $client = User::factory()->create();
        $booking = Booking::factory()->confirmed()->create(['client_id' => $client->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/request-cancellation", ['reason' => 'again'])->assertStatus(422);
        $this->assertErrorText($response, 'A cancellation request is already pending for this booking.');
    }

    public function test_approving_a_cancellation_notifies_both_sides(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/approve")
            ->assertOk()->assertJsonPath('message', 'Cancellation approved.')->assertJsonPath('data.status', 'cancelled');

        $this->assertGreaterThan(0, $this->notified($client));
        $this->assertGreaterThan(0, $this->notified($photographer));
    }

    public function test_rejecting_a_cancellation_keeps_it_confirmed_and_notifies_the_client(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id]);
        $booking->update(['cancellation_reason' => 'x', 'cancellation_requested_at' => now()]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/cancellation/reject")
            ->assertOk()->assertJsonPath('message', 'Cancellation request declined.')->assertJsonPath('data.status', 'confirmed');

        $this->assertGreaterThan(0, $this->notified($client));
    }

    // ---------------------------------------------------------------- BKG-22 / BKG-23 expiry

    public function test_overdue_pending_request_expires_with_its_reason(): void
    {
        $booking = Booking::factory()->create(['hold_expires_at' => now()->subHour()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Expired, $fresh->status);
        $this->assertSame('Request expired — the photographer did not respond in time.', $fresh->cancellation_reason);
        $this->assertNull($fresh->hold_expires_at);
    }

    public function test_pending_request_expires_when_the_event_start_has_passed_even_inside_its_hold(): void
    {
        $booking = Booking::factory()->create([
            'hold_expires_at' => now()->addHours(5),
            'event_date' => now()->subDay()->format('Y-m-d'),
            'start_time' => '09:00',
        ]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->status);
    }

    public function test_unpaid_accepted_booking_expires_after_its_payment_deadline(): void
    {
        $booking = Booking::factory()->accepted()->create(['hold_expires_at' => now()->subMinute()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Expired, $fresh->status);
        $this->assertSame('Booking expired — the reservation payment was not completed in time.', $fresh->cancellation_reason);
    }

    public function test_paid_booking_is_never_expired_by_the_payment_deadline_job(): void
    {
        $booking = Booking::factory()->confirmed()->create(['hold_expires_at' => now()->subHour()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    /** BKG-23 says "the client is notified". The expiry job currently sends no notification, so this is expected to FAIL until that is added. */
    public function test_client_is_notified_when_an_unpaid_booking_expires(): void
    {
        $client = User::factory()->create();
        Booking::factory()->accepted()->create(['client_id' => $client->id, 'hold_expires_at' => now()->subMinute()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertGreaterThan(0, $this->notified($client));
    }

    public function test_client_is_notified_when_a_pending_request_expires(): void
    {
        $client = User::factory()->create();
        Booking::factory()->create(['client_id' => $client->id, 'hold_expires_at' => now()->subHour()]);

        app(ExpireStaleBookingHoldsAction::class)->execute();

        $this->assertGreaterThan(0, $this->notified($client));
    }

    public function test_reschedule_request_and_approval_are_written_to_the_activity_log(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $newDate = $this->day(20);
        $oldDate = $this->day(10);
        $this->windowOn($photographer, $newDate);
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $oldDate, 'start_time' => '09:00',
        ]);

        Sanctum::actingAs($client);
        $this->postJson("/api/client/bookings/{$booking->id}/reschedule", [
            'event_date' => $newDate, 'start_time' => '09:00', 'reason' => 'Venue changed',
        ])->assertOk();

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$booking->id}/reschedule/approve")->assertOk();

        $requested = \App\Models\ActivityLog::where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)->where('action', 'booking.reschedule_requested')->first();
        $approved = \App\Models\ActivityLog::where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)->where('action', 'booking.reschedule_approved')->first();

        $this->assertNotNull($requested);
        $this->assertStringContainsString('reason: Venue changed', $requested->description);
        $this->assertNotNull($approved);
        $this->assertStringContainsString("{$oldDate} 09:00 → {$newDate} 09:00", $approved->description);
    }

    public function test_modification_request_and_approval_are_written_to_the_activity_log(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $this->day(10), 'guest_count' => 20,
        ]);

        Sanctum::actingAs($client);
        $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 50], 'reason' => 'More guests are coming',
        ])->assertOk();

        Sanctum::actingAs($photographer);
        $this->postJson("/api/photographer/bookings/{$booking->id}/modification/approve")->assertOk();

        $requested = \App\Models\ActivityLog::where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)->where('action', 'booking.modification_requested')->first();

        $this->assertNotNull($requested);
        $this->assertStringContainsString('reason: More guests are coming', $requested->description);
        $this->assertTrue(
            \App\Models\ActivityLog::where('subject_type', Booking::class)
                ->where('subject_id', $booking->id)->where('action', 'booking.modification_approved')->exists()
        );
    }

    public function test_booking_details_include_the_change_history_for_both_sides(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->accepted()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => $this->day(10), 'guest_count' => 20,
        ]);

        Sanctum::actingAs($client);
        $this->postJson("/api/client/bookings/{$booking->id}/request-modification", [
            'changes' => ['guest_count' => 50], 'reason' => 'More guests are coming',
        ])->assertOk();

        $asClient = $this->getJson("/api/client/bookings/{$booking->id}")->assertOk()->json('data.history');
        $this->assertSame('booking.modification_requested', $asClient[0]['action']);
        $this->assertStringContainsString('reason: More guests are coming', $asClient[0]['description']);

        Sanctum::actingAs($photographer);
        $asPhotographer = $this->getJson("/api/photographer/bookings/{$booking->id}")->assertOk()->json('data.history');
        $this->assertSame('booking.modification_requested', $asPhotographer[0]['action']);
    }

    // ---------------------------------------------------------------- BST-03 accept a non-pending booking

    public function test_only_pending_bookings_can_be_accepted(): void
    {
        $photographer = $this->approvedPhotographer();
        Sanctum::actingAs($photographer);

        $cases = [
            'confirmed' => Booking::factory()->accepted(),
            'completed' => Booking::factory()->completed(),
            'cancelled' => Booking::factory()->rejected(),
        ];

        foreach ($cases as $label => $factory) {
            $booking = $factory->create(['photographer_id' => $photographer->id]);

            $response = $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(422);

            $this->assertErrorText($response, 'Only pending booking requests can be accepted.');
        }
    }

    // ---------------------------------------------------------------- BST-10 / BST-12 admin resolves a no-show report

    protected function pendingNoShow(User $client, User $photographer): Booking
    {
        return Booking::factory()->confirmed()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
            'non_completion_reason' => BookingNonCompletionReason::PhotographerNoShow,
            'non_completion_reported_by' => $client->id,
            'non_completion_reported_at' => now()->subHours(2),
            'non_completion_review_status' => 'pending_admin',
        ]);
    }

    public function test_admin_upholding_a_report_marks_the_booking_no_show_and_notifies_both(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->pendingNoShow($client, $photographer);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson("/api/admin/bookings/{$booking->id}/non-completion/resolve", [
            'decision' => 'upheld', 'notes' => 'Photographer did not arrive.',
        ])->assertOk()->assertJsonPath('message', 'Dispute resolved.');

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::NoShow, $fresh->status);
        $this->assertSame('upheld', $fresh->non_completion_review_status);
        $this->assertSame('Photographer did not arrive.', $fresh->non_completion_admin_notes);
        $this->assertGreaterThan(0, $this->notified($client));
        $this->assertGreaterThan(0, $this->notified($photographer));
    }

    public function test_admin_overturning_a_report_restores_the_booking(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->pendingNoShow($client, $photographer);
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson("/api/admin/bookings/{$booking->id}/non-completion/resolve", ['decision' => 'overturned'])->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $fresh->status);
        $this->assertNull($fresh->non_completion_reason);
        $this->assertSame('overturned', $fresh->non_completion_review_status);
    }

    public function test_admin_resolution_rejects_an_invalid_decision_or_a_booking_without_a_report(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $reported = $this->pendingNoShow($client, $photographer);
        $clean = Booking::factory()->confirmed()->create();
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson("/api/admin/bookings/{$reported->id}/non-completion/resolve", ['decision' => 'maybe'])->assertStatus(422);

        $response = $this->postJson("/api/admin/bookings/{$clean->id}/non-completion/resolve", ['decision' => 'upheld'])->assertStatus(422);
        $this->assertErrorText($response, 'This booking has no no-show report awaiting review.');
    }

    public function test_only_an_admin_can_resolve_a_no_show_report(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->pendingNoShow($client, $photographer);

        Sanctum::actingAs($client);
        $this->postJson("/api/admin/bookings/{$booking->id}/non-completion/resolve", ['decision' => 'upheld'])->assertStatus(403);

        Sanctum::actingAs($photographer);
        $this->postJson("/api/admin/bookings/{$booking->id}/non-completion/resolve", ['decision' => 'upheld'])->assertStatus(403);
    }
}