<?php

namespace Tests\Feature\Photographer;

use App\Models\AvailabilityWindow;
use App\Models\CustomPackageComponent;
use App\Models\CustomPackageConfig;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerProfile;
use App\Models\PhotographerPortfolioImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CPK-02 .. CPK-05: the four ways a photographer can charge for a custom package
 * (fixed price, by the hour, by the day, by the person).
 *
 * Part 1 checks what the photographer is allowed to save.
 * Part 2 checks the total a client is charged when booking.
 *
 * Every booking below also selects one flat extra priced at 1000, so the expected
 * subtotal is always: (rate x quantity) + 1000. The platform fee is added on top,
 * the same way CreateBookingTest does it.
 */
class CustomPackagePricingMethodTest extends TestCase
{
    use RefreshDatabase;

    private const EXTRA = 1000;

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    /** Same setup as CreateBookingTest::bookablePhotographer(), plus a second open date. */
    protected function bookablePhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();
        PhotographerPortfolioImage::factory()->for($user)->count(6)->create();
        Package::factory()->for($user)->published()->create();
        \App\Models\PhotographerPaymentConfig::factory()->for($user)->create();

        foreach ([10, 11] as $days) {
            AvailabilityWindow::factory()->for($user)->create([
                'date' => now()->addDays($days)->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '18:00',
            ]);
        }

        return $user;
    }

    /** Turns on custom packages for the photographer with the given pricing settings. */
    protected function customConfig(User $photographer, array $settings): void
    {
        CustomPackageConfig::factory()->for($photographer)->create(['enabled' => true] + $settings);
    }

    /** Booking request for a custom package. $extra overrides or adds request fields. */
    protected function customBookingPayload(User $photographer, array $extra = []): array
    {
        $extraComponent = CustomPackageComponent::factory()->for($photographer)->create(['price_addition' => self::EXTRA]);

        return $extra + [
            'photographer_id' => $photographer->id,
            'is_custom_package' => true,
            'custom_component_ids' => [$extraComponent->id],
            'event_type' => 'wedding',
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '09:00',
            'location_type' => 'studio',
        ];
    }

    protected function extraSession(int $daysAhead, string $startTime, int $number): array
    {
        return [
            'label' => "Session {$number}",
            'event_type' => 'wedding',
            'event_date' => now()->addDays($daysAhead)->format('Y-m-d'),
            'start_time' => $startTime,
        ];
    }

    protected function book(User $photographer, array $payload)
    {
        Sanctum::actingAs(User::factory()->create());

        return $this->postJson('/api/client/bookings', $payload);
    }

    protected function expectedTotal(float $subtotal): float
    {
        return $subtotal + config('platform.fee', 30.00);
    }

    // ---------------------------------------------------------------------
    // Part 1: saving each charge method (photographer side)
    // ---------------------------------------------------------------------

    public function test_hourly_pricing_requires_an_hourly_rate(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'hourly',
        ])->assertStatus(422)->assertJsonValidationErrors('hourly_rate');
    }

    public function test_hourly_rate_of_zero_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'hourly', 'hourly_rate' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('hourly_rate');
    }

    public function test_hourly_pricing_can_be_saved_with_a_rate_and_hour_range(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'hourly', 'hourly_rate' => 1000, 'min_hours' => 1, 'max_hours' => 8,
        ])->assertOk();

        $this->assertDatabaseHas('custom_package_configs', ['user_id' => $user->id, 'pricing_model' => 'hourly']);
    }

    public function test_maximum_hours_cannot_be_less_than_minimum_hours(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'hourly', 'hourly_rate' => 1000, 'min_hours' => 5, 'max_hours' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors('max_hours');
    }

    public function test_per_day_pricing_requires_a_daily_rate(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'per_day',
        ])->assertStatus(422)->assertJsonValidationErrors('unit_rate');
    }

    public function test_per_day_pricing_can_be_saved_with_a_daily_rate(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'per_day', 'unit_rate' => 5000,
        ])->assertOk();

        $this->assertDatabaseHas('custom_package_configs', ['user_id' => $user->id, 'pricing_model' => 'per_day']);
    }

    public function test_per_person_pricing_requires_a_rate_per_person(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'per_person',
        ])->assertStatus(422)->assertJsonValidationErrors('unit_rate');
    }

    public function test_per_person_pricing_can_be_saved_with_a_rate_and_a_people_limit(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'per_person', 'unit_rate' => 500, 'max_people' => 10,
        ])->assertOk();

        $this->assertDatabaseHas('custom_package_configs', ['user_id' => $user->id, 'pricing_model' => 'per_person']);
    }

    public function test_an_unknown_pricing_method_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'pricing_model' => 'monthly', 'base_fee' => 2000,
        ])->assertStatus(422)->assertJsonValidationErrors('pricing_model');
    }

    // ---------------------------------------------------------------------
    // Part 2: what the client is charged when booking
    // ---------------------------------------------------------------------

    public function test_hourly_booking_charges_rate_times_the_chosen_hours(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, [
            'pricing_model' => 'hourly', 'hourly_rate' => 1000, 'min_hours' => 1, 'max_hours' => 8,
        ]);

        $response = $this->book($photographer, $this->customBookingPayload($photographer, ['custom_hours' => 3]));

        $response->assertCreated();
        $this->assertEquals($this->expectedTotal(1000 * 3 + self::EXTRA), $response->json('data.total_price'));
    }

    public function test_hourly_booking_outside_the_allowed_hour_range_is_rejected(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, [
            'pricing_model' => 'hourly', 'hourly_rate' => 1000, 'min_hours' => 2, 'max_hours' => 8,
        ]);

        foreach ([1, 9] as $hours) {
            $this->book($photographer, $this->customBookingPayload($photographer, ['custom_hours' => $hours]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('custom_hours');
        }
    }

    public function test_per_day_booking_charges_the_daily_rate_for_one_date(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, ['pricing_model' => 'per_day', 'unit_rate' => 5000, 'coverage_hours' => 4]);

        $response = $this->book($photographer, $this->customBookingPayload($photographer));

        $response->assertCreated();
        $this->assertEquals($this->expectedTotal(5000 * 1 + self::EXTRA), $response->json('data.total_price'));
    }

    public function test_per_day_two_sessions_on_the_same_date_count_as_one_day(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, [
            'pricing_model' => 'per_day', 'unit_rate' => 5000, 'coverage_hours' => 4,
            'allows_multiple_sessions' => true, 'max_sessions' => 5,
        ]);

        $response = $this->book($photographer, $this->customBookingPayload($photographer, [
            'additional_schedules' => [$this->extraSession(10, '14:00', 2)],
        ]));

        $response->assertCreated();
        $this->assertEquals($this->expectedTotal(5000 * 1 + self::EXTRA), $response->json('data.total_price'));
    }

    public function test_per_day_sessions_on_different_dates_are_charged_once_per_date(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, [
            'pricing_model' => 'per_day', 'unit_rate' => 5000, 'coverage_hours' => 4,
            'allows_multiple_sessions' => true, 'max_sessions' => 5,
        ]);

        $response = $this->book($photographer, $this->customBookingPayload($photographer, [
            'additional_schedules' => [$this->extraSession(11, '09:00', 2)],
        ]));

        $response->assertCreated();
        $this->assertEquals($this->expectedTotal(5000 * 2 + self::EXTRA), $response->json('data.total_price'));
    }

    public function test_an_extra_session_is_rejected_when_the_custom_package_is_single_session(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, [
            'pricing_model' => 'per_day', 'unit_rate' => 5000, 'coverage_hours' => 4, 'allows_multiple_sessions' => false,
        ]);

        $this->book($photographer, $this->customBookingPayload($photographer, [
            'additional_schedules' => [$this->extraSession(11, '09:00', 2)],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'additional_schedules' => "This photographer's custom package covers a single photography session. Book separate services as separate bookings.",
        ]);
    }

    public function test_a_booking_cannot_have_more_sessions_than_the_custom_package_allows(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, [
            'pricing_model' => 'per_day', 'unit_rate' => 5000, 'coverage_hours' => 4,
            'allows_multiple_sessions' => true, 'max_sessions' => 2,
        ]);

        // Session 1 + two extra sessions = 3, but the package allows 2.
        $this->book($photographer, $this->customBookingPayload($photographer, [
            'additional_schedules' => [
                $this->extraSession(10, '14:00', 2),
                $this->extraSession(11, '09:00', 3),
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'additional_schedules' => 'This custom package allows up to 2 sessions per booking.',
        ]);
    }

    public function test_per_person_booking_charges_the_rate_times_the_number_of_people(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, ['pricing_model' => 'per_person', 'unit_rate' => 500, 'max_people' => 10]);

        $response = $this->book($photographer, $this->customBookingPayload($photographer, ['custom_people' => 4]));

        $response->assertCreated();
        $this->assertEquals($this->expectedTotal(500 * 4 + self::EXTRA), $response->json('data.total_price'));
    }

    public function test_per_person_booking_needs_the_number_of_people_and_respects_the_limit(): void
    {
        $photographer = $this->bookablePhotographer();
        $this->customConfig($photographer, ['pricing_model' => 'per_person', 'unit_rate' => 500, 'max_people' => 10]);

        // Missing number of people
        $this->book($photographer, $this->customBookingPayload($photographer))
            ->assertStatus(422)->assertJsonValidationErrors('custom_people');

        // More people than the photographer covers
        $this->book($photographer, $this->customBookingPayload($photographer, ['custom_people' => 11]))
            ->assertStatus(422)->assertJsonValidationErrors('custom_people');
    }
}