<?php

namespace Tests\Feature\Booking;

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiSessionBookingTest extends TestCase
{
    use RefreshDatabase;

    private function day(int $plus): string
    {
        return now()->addDays($plus)->format('Y-m-d');
    }

    /** Bookable photographer; the package is 120 min, no buffer, and open on +10, +17 and +24 days. */
    private function photographer(bool $multi = true, ?int $max = null): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();
        PhotographerPortfolioImage::factory()->for($user)->count(6)->create();
        Package::factory()->for($user)->published()->create([
            'duration_minutes' => 120,
            'buffer_minutes' => 0,
            'allows_multiple_sessions' => $multi,
            'max_sessions' => $multi ? $max : null,
        ]);
        PhotographerPaymentConfig::factory()->for($user)->create();

        foreach ([10, 17, 24] as $plus) {
            AvailabilityWindow::factory()->for($user)->create([
                'date' => $this->day($plus), 'start_time' => '09:00', 'end_time' => '18:00',
            ]);
        }

        return $user;
    }

    private function payload(User $photographer, array $extra = []): array
    {
        return [
            'photographer_id' => $photographer->id,
            'package_id' => $photographer->packages()->first()->id,
            'event_type' => 'wedding',
            'event_date' => $this->day(10),
            'start_time' => '09:00',
            'location_type' => 'studio',
            'additional_schedules' => $extra,
        ];
    }

    private function extraSession(int $plus, string $label = 'Session 2', array $more = []): array
    {
        return array_merge(['label' => $label, 'event_date' => $this->day($plus), 'start_time' => '09:00'], $more);
    }

    // BKG-17: a multi-session booking stays Pending.
    public function test_multi_session_booking_is_saved_as_pending(): void
    {
        $photographer = $this->photographer();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/client/bookings', $this->payload($photographer, [$this->extraSession(24)]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', Booking::first()->status->value);
    }

    // BKG-14: every session is saved and listed for both the client and the photographer.
    public function test_every_session_is_saved_and_listed_for_client_and_photographer(): void
    {
        $photographer = $this->photographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $id = $this->postJson('/api/client/bookings', $this->payload($photographer, [$this->extraSession(24, 'Prenup')]))
            ->assertCreated()->json('data.id');

        $this->assertDatabaseCount('booking_schedules', 1);

        $this->getJson("/api/client/bookings/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.schedules');

        Sanctum::actingAs($photographer);
        $this->getJson("/api/photographer/bookings/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.schedules');
    }

    // BKG-10: a session with no duration given uses the package's; end time is derived by the server.
    public function test_a_session_without_a_duration_uses_the_package_duration(): void
    {
        $photographer = $this->photographer();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/client/bookings', $this->payload($photographer, [$this->extraSession(24)]))->assertCreated();

        $extra = Booking::first()->schedules()->first();
        $this->assertSame(120, (int) $extra->duration_minutes);
        $this->assertSame('11:00', substr((string) $extra->end_time, 0, 5));
    }

    // BKG-13: dates between two sessions are not blocked for another client.
    public function test_dates_between_two_sessions_stay_open_for_another_client(): void
    {
        $photographer = $this->photographer();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/client/bookings', $this->payload($photographer, [$this->extraSession(24)]))->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $between = $this->payload($photographer);
        $between['event_date'] = $this->day(17);

        $this->postJson('/api/client/bookings', $between)->assertCreated();
    }

    // BKG-16: a package that does not allow multiple sessions refuses extra sessions.
    public function test_package_without_multiple_sessions_refuses_extra_sessions(): void
    {
        $photographer = $this->photographer(multi: false);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/client/bookings', $this->payload($photographer, [$this->extraSession(24)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.additional_schedules.0', 'The selected package covers a single photography session. Book separate services as separate bookings.');

        $this->assertDatabaseCount('bookings', 0);
    }

    // BKG-16: going over the package's maximum is refused.
    public function test_more_sessions_than_the_package_maximum_are_refused(): void
    {
        $photographer = $this->photographer(multi: true, max: 2);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/client/bookings', $this->payload($photographer, [
            $this->extraSession(17, 'Session 2'), $this->extraSession(24, 'Session 3'),
        ]))->assertStatus(422)
            ->assertJsonPath('errors.additional_schedules.0', 'This package allows up to 2 sessions per booking.');

        $this->assertDatabaseCount('bookings', 0);
    }

    // BKG-16: two sessions that overlap on the same date are refused.
    public function test_overlapping_sessions_on_the_same_date_are_refused(): void
    {
        $photographer = $this->photographer();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/client/bookings', $this->payload($photographer, [
            $this->extraSession(10, 'Session 2', ['start_time' => '10:00']),
        ]))->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }
}