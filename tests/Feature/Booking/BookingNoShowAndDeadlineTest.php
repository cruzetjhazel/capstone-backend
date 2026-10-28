<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingNonCompletionReason;
use App\Enums\BookingStatus;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use App\Services\Photographer\Booking\BookingDeadlineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingNoShowAndDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private const NOTES = 'The photographer never arrived and did not answer calls.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    /** A Confirmed, paid booking whose scheduled start was yesterday 09:00 (ends 11:30). */
    protected function startedBooking(User $client, User $photographer, array $overrides = []): Booking
    {
        return Booking::factory()->confirmed()->create(array_merge([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:30',
        ], $overrides));
    }

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

    // ---------------------------------------------------------------- BST-09 client reports a photographer no-show

    public function test_client_can_report_a_photographer_no_show_after_the_start_time(): void
    {
        $admin = User::factory()->administrator()->create();
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->startedBooking($client, $photographer);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertOk()->assertJsonPath('message', 'Reported.')->assertJsonPath('data.status', 'confirmed');

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $fresh->status);
        $this->assertSame(BookingNonCompletionReason::PhotographerNoShow, $fresh->non_completion_reason);
        $this->assertSame('pending_admin', $fresh->non_completion_review_status);
        $this->assertSame($client->id, (int) $fresh->non_completion_reported_by);
        $this->assertEqualsWithDelta(
            16 * 3600,
            abs($fresh->non_completion_dispute_deadline_at->diffInSeconds($fresh->non_completion_reported_at)),
            5
        );
        $this->assertGreaterThan(0, $this->notified($photographer));
        $this->assertGreaterThan(0, $this->notified($admin));
    }

    public function test_client_report_is_rejected_before_the_scheduled_start(): void
    {
        $client = User::factory()->create();
        $booking = $this->startedBooking($client, $this->approvedPhotographer(), [
            'event_date' => now()->addDays(10)->format('Y-m-d'),
        ]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertStatus(422);

        $this->assertErrorText($response, "This booking's scheduled time has not started yet.");
        $this->assertNull($booking->fresh()->non_completion_reason);
    }

    public function test_client_report_needs_a_statement_of_at_least_20_characters(): void
    {
        $client = User::factory()->create();
        $booking = $this->startedBooking($client, $this->approvedPhotographer());
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", ['reason' => 'photographer_no_show'])
            ->assertStatus(422);
        $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => 'too short',
        ])->assertStatus(422);
    }

    public function test_client_report_is_rejected_after_the_reporting_period_ends(): void
    {
        $client = User::factory()->create();
        $booking = $this->startedBooking($client, $this->approvedPhotographer(), [
            'event_date' => now()->subDays(6)->format('Y-m-d'),
        ]);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertStatus(422);

        $this->assertErrorText($response, 'The reporting period for this booking ended on');
    }

    public function test_client_report_is_still_allowed_when_the_photographer_moved_the_tracker_to_editing(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->withServiceStatus(ServiceTrackerStatus::Editing)->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '11:30',
        ]);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertOk();
    }

    public function test_client_cannot_report_a_client_no_show_or_file_a_second_report(): void
    {
        $client = User::factory()->create();
        $booking = $this->startedBooking($client, $this->approvedPhotographer());
        Sanctum::actingAs($client);

        $wrong = $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'client_no_show', 'notes' => self::NOTES,
        ])->assertStatus(422);
        $this->assertErrorText($wrong, "You can only report the other party's non-attendance.");

        $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertOk();

        $again = $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertStatus(422);
        $this->assertErrorText($again, 'This booking already has a no-show report.');
    }

    public function test_another_client_cannot_report_on_someone_elses_booking(): void
    {
        $booking = $this->startedBooking(User::factory()->create(), $this->approvedPhotographer());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/client/bookings/{$booking->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertStatus(403);
    }

    // ---------------------------------------------------------------- dispute window (BST-09 / BST-11)

    protected function reportedBooking(User $client, User $photographer, User $reporter, BookingNonCompletionReason $reason, array $overrides = []): Booking
    {
        return $this->startedBooking($client, $photographer, array_merge([
            'non_completion_reason' => $reason,
            'non_completion_reported_by' => $reporter->id,
            'non_completion_reported_at' => now()->subHour(),
            'non_completion_dispute_deadline_at' => now()->addHours(15),
            'non_completion_review_status' => 'pending_admin',
        ], $overrides));
    }

    public function test_photographer_can_dispute_a_client_report_inside_the_window(): void
    {
        $admin = User::factory()->administrator()->create();
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->reportedBooking($client, $photographer, $client, BookingNonCompletionReason::PhotographerNoShow);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/dispute-non-completion", [
            'reason' => 'I was at the venue on time.',
        ])->assertOk()->assertJsonPath('message', 'Dispute submitted.');

        $this->assertNotNull($booking->fresh()->non_completion_disputed_at);
        $this->assertDatabaseHas('reports', ['reason' => 'No-show dispute', 'reference_id' => (string) $booking->id]);
        $this->assertGreaterThan(0, $this->notified($admin));
    }

    public function test_the_reporter_cannot_dispute_their_own_report(): void
    {
        $client = User::factory()->create();
        $booking = $this->reportedBooking($client, $this->approvedPhotographer(), $client, BookingNonCompletionReason::PhotographerNoShow);
        Sanctum::actingAs($client);

        $response = $this->postJson("/api/client/bookings/{$booking->id}/dispute-non-completion", ['reason' => 'I am disputing my own report.'])
            ->assertStatus(422);
        $this->assertErrorText($response, 'Only the reported party can dispute this.');
    }

    public function test_dispute_is_refused_after_the_16_hour_window(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->reportedBooking($client, $photographer, $client, BookingNonCompletionReason::PhotographerNoShow, [
            'non_completion_dispute_deadline_at' => now()->subMinute(),
        ]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/dispute-non-completion", ['reason' => 'Disputing after the deadline.'])
            ->assertStatus(422);
        $this->assertErrorText($response, 'The dispute period for this report has ended.');
    }

    public function test_a_report_can_only_be_disputed_once(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->reportedBooking($client, $photographer, $client, BookingNonCompletionReason::PhotographerNoShow);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/dispute-non-completion", ['reason' => 'First dispute of this report.'])->assertOk();
        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/dispute-non-completion", ['reason' => 'Second dispute of this report.'])
            ->assertStatus(422);
        $this->assertErrorText($response, 'This report has already been disputed.');
    }

    public function test_a_booking_without_a_report_cannot_be_disputed(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->startedBooking(User::factory()->create(), $photographer);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/dispute-non-completion", ['reason' => 'There is no report on this booking.'])
            ->assertStatus(422);
        $this->assertErrorText($response, 'This booking has no no-show report to dispute.');
    }

    // ---------------------------------------------------------------- BST-11 photographer reports a client no-show

    public function test_photographer_can_report_a_client_no_show_and_both_client_and_admin_are_notified(): void
    {
        $admin = User::factory()->administrator()->create();
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->startedBooking($client, $photographer);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/report-non-completion", ['reason' => 'client_no_show'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $fresh = $booking->fresh();
        $this->assertSame('pending_admin', $fresh->non_completion_review_status);
        $this->assertSame(BookingNonCompletionReason::ClientNoShow, $fresh->non_completion_reason);
        $this->assertNotNull($fresh->non_completion_dispute_deadline_at);
        $this->assertGreaterThan(0, $this->notified($client));
        $this->assertGreaterThan(0, $this->notified($admin));
    }

    public function test_photographer_report_is_rejected_before_the_start_time(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = $this->startedBooking(User::factory()->create(), $photographer, [
            'event_date' => now()->addDays(10)->format('Y-m-d'),
        ]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/report-non-completion", ['reason' => 'client_no_show'])
            ->assertStatus(422);
        $this->assertErrorText($response, "This booking's scheduled time has not started yet.");
    }

    public function test_photographer_report_is_rejected_once_the_booking_is_delivered(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->withServiceStatus(ServiceTrackerStatus::Delivered)->create([
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '11:30',
        ]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/report-non-completion", ['reason' => 'client_no_show'])
            ->assertStatus(422);
        $this->assertErrorText($response, 'This booking has already progressed past the point of a no-show report.');
    }

    public function test_photographer_cannot_report_a_photographer_no_show_or_a_non_confirmed_booking(): void
    {
        $photographer = $this->approvedPhotographer();
        $confirmed = $this->startedBooking(User::factory()->create(), $photographer);
        $pending = Booking::factory()->create(['photographer_id' => $photographer->id]);
        Sanctum::actingAs($photographer);

        $wrong = $this->postJson("/api/photographer/bookings/{$confirmed->id}/report-non-completion", [
            'reason' => 'photographer_no_show', 'notes' => self::NOTES,
        ])->assertStatus(422);
        $this->assertErrorText($wrong, "You can only report the other party's non-attendance.");

        $notConfirmed = $this->postJson("/api/photographer/bookings/{$pending->id}/report-non-completion", ['reason' => 'client_no_show'])
            ->assertStatus(422);
        $this->assertErrorText($notConfirmed, 'Only a confirmed booking can be reported as non-completed.');
    }

    public function test_client_can_dispute_a_photographers_client_no_show_report(): void
    {
        $client = User::factory()->create();
        $photographer = $this->approvedPhotographer();
        $booking = $this->reportedBooking($client, $photographer, $photographer, BookingNonCompletionReason::ClientNoShow);
        Sanctum::actingAs($client);

        $this->postJson("/api/client/bookings/{$booking->id}/dispute-non-completion", ['reason' => 'I was there.'])
            ->assertOk()->assertJsonPath('message', 'Dispute submitted.');
    }

    // ---------------------------------------------------------------- BKG-22 / BKG-23 deadline maths

    public function test_decision_deadline_is_48_hours_or_the_event_start_whichever_is_first(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $deadlines = app(BookingDeadlineService::class);

        $this->assertTrue($deadlines->decisionDeadlineForRequest('2026-11-30', '09:00')->equalTo(Carbon::parse('2026-10-20 10:00:00')));
        $this->assertTrue($deadlines->decisionDeadlineForRequest('2026-10-18', '20:00')->equalTo(Carbon::parse('2026-10-18 20:00:00')));
    }

    public function test_payment_deadline_is_the_earlier_of_24_hours_or_3_hours_before_the_first_session(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $deadlines = app(BookingDeadlineService::class);

        $far = Booking::factory()->create(['event_date' => '2026-11-30', 'start_time' => '09:00']);
        $soon = Booking::factory()->create(['event_date' => '2026-10-19', 'start_time' => '09:00']);

        $this->assertTrue($deadlines->paymentDeadline($far)->equalTo(Carbon::parse('2026-10-19 10:00:00')));
        $this->assertTrue($deadlines->paymentDeadline($soon)->equalTo(Carbon::parse('2026-10-19 06:00:00')));
    }

    public function test_payment_deadline_is_refused_when_the_event_starts_too_soon(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $booking = Booking::factory()->create(['event_date' => '2026-10-18', 'start_time' => '12:00', 'end_time' => '14:30']);

        try {
            app(BookingDeadlineService::class)->paymentDeadline($booking);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'This event starts too soon to collect a reservation payment, so it cannot be accepted. Please decline the request.',
                $e->errors()['booking'][0]
            );
        }
    }

    // BST-14: the accept endpoint itself refuses a request that starts too soon.
    public function test_accepting_a_request_that_starts_too_soon_is_refused_and_stays_pending(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'event_date' => '2026-10-18', 'start_time' => '12:00', 'end_time' => '14:30',
            'hold_expires_at' => Carbon::parse('2026-10-18 12:00:00'),
        ]);
        Sanctum::actingAs($photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertStatus(422);

        $this->assertErrorText($response, 'This event starts too soon to collect a reservation payment, so it cannot be accepted. Please decline the request.');
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_accepting_sets_the_payment_deadline_on_the_booking(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'event_date' => '2026-11-30', 'start_time' => '09:00',
            'hold_expires_at' => Carbon::parse('2026-10-20 10:00:00'),
        ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/accept")->assertOk();

        $this->assertTrue($booking->fresh()->hold_expires_at->equalTo(Carbon::parse('2026-10-19 10:00:00')));
    }
}