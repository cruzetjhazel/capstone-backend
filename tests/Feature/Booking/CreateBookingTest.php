<?php

namespace Tests\Feature\Booking;

use App\Models\AddOn;
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

class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function bookablePhotographer(): User
{
    $user = User::factory()->photographer()->create();
    PhotographerApplication::factory()->for($user)->approved()->create();
    PhotographerProfile::factory()->for($user)->complete()->create();
    PhotographerPortfolioImage::factory()->for($user)->count(6)->create();
    Package::factory()->for($user)->published()->create();
    \App\Models\PhotographerPaymentConfig::factory()->for($user)->create();

    AvailabilityWindow::factory()->for($user)->create([
        'date' => now()->addDays(10)->format('Y-m-d'),
        'start_time' => '09:00',
        'end_time' => '18:00', // was 17:00 — 480 min duration + 30 min buffer needs 510 min of room
    ]);

    return $user;
}

    protected function validPayload(User $photographer): array
{
    $package = $photographer->packages()->first();

    return [
        'photographer_id' => $photographer->id,
        'package_id' => $package?->id, // was $package->id
        'event_type' => 'wedding',
        'event_date' => now()->addDays(10)->format('Y-m-d'),
        'start_time' => '09:00',
        'location_type' => 'studio',
    ];
}

    public function test_client_can_create_a_booking_for_a_bookable_photographer(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/client/bookings', $this->validPayload($photographer));

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('bookings', ['client_id' => $client->id, 'photographer_id' => $photographer->id]);
    }

    public function test_guest_cannot_create_a_booking(): void
    {
        $photographer = $this->bookablePhotographer();

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(401);
    }

    public function test_photographer_account_cannot_create_a_booking(): void
    {
        $photographer = $this->bookablePhotographer();
        $anotherPhotographer = User::factory()->photographer()->create();
        Sanctum::actingAs($anotherPhotographer);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(403);
    }

    public function test_booking_is_rejected_for_a_non_bookable_photographer(): void
    {
        // Approved but no packages / incomplete profile -> not bookable
        $photographer = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($photographer)->approved()->create();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(422);
    }

    public function test_booking_rejected_when_start_time_not_in_available_slots(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        // Usual hours must exist, otherwise the day counts as open all day.
        \App\Models\BookingHour::create([
            'user_id' => $photographer->id,
            'day_of_week' => now()->addDays(10)->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
        $payload['start_time'] = '08:00'; // before opening hours, and the package still fits in the day

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
    }

    // BKG-29: the server refuses a blocked date even if the screen is bypassed.
    public function test_booking_is_rejected_on_a_date_the_photographer_blocked(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        \App\Models\BlockedDate::factory()->for($photographer)->create(['date' => $payload['event_date']]);

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
        $this->assertDatabaseCount('bookings', 0);
    }

    // Business rule (revised): a slot is only ever truly "taken" once a
    // booking for it has a CONFIRMED payment. Multiple clients must be able
    // to request — and even be accepted for — the same overlapping slot,
    // so a photographer can keep a backup request alive in case the first
    // client never pays. See CreateBookingAction / AvailabilityService.
    public function test_multiple_clients_can_request_the_same_unpaid_slot(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertCreated();

        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertCreated();
    }

    public function test_conflicting_time_is_rejected_once_a_booking_is_paid_and_confirmed(): void
    {
        $photographer = $this->bookablePhotographer();
        $firstClient = User::factory()->create();

        \App\Models\Booking::factory()->create([
            'client_id' => $firstClient->id,
            'photographer_id' => $photographer->id,
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:30',
            'status' => \App\Enums\BookingStatus::Confirmed,
            'payment_status' => \App\Enums\BookingPaymentStatus::FullyPaid,
        ]);

        $otherClient = User::factory()->create();
        Sanctum::actingAs($otherClient);

        $this->postJson('/api/client/bookings', $this->validPayload($photographer))->assertStatus(422);
    }

    public function test_package_must_belong_to_the_selected_photographer(): void
    {
        $photographer = $this->bookablePhotographer();
        $otherPhotographer = $this->bookablePhotographer();
        $foreignPackage = $otherPhotographer->packages()->first();

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['package_id'] = $foreignPackage->id;

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
    }

    public function test_add_ons_are_snapshotted_and_priced(): void
    {
        $photographer = $this->bookablePhotographer();
        $addOn = AddOn::factory()->for($photographer)->create(['price' => 1500]);
        $package = $photographer->packages()->first();

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['add_on_ids'] = [$addOn->id];

        $response = $this->postJson('/api/client/bookings', $payload);

        $response->assertCreated();
        $this->assertEquals($package->price + 1500 + config('platform.fee', 30.00), $response->json('data.total_price'));
    }

    public function test_custom_package_booking_uses_configured_pricing(): void
    {
        $photographer = $this->bookablePhotographer();
        CustomPackageConfig::factory()->for($photographer)->create(['enabled' => true, 'base_fee' => 2000]);
        $component = CustomPackageComponent::factory()->for($photographer)->create(['price_addition' => 1000, 'duration_minutes' => 120]);

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        unset($payload['package_id']);
        $payload['is_custom_package'] = true;
        $payload['custom_component_ids'] = [$component->id];

        $response = $this->postJson('/api/client/bookings', $payload);

        $response->assertCreated();
        $this->assertEquals(2000 + 1000 + config('platform.fee', 30.00), $response->json('data.total_price'));
    }

    public function test_event_address_required_for_non_studio_location(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $payload['location_type'] = 'client_location';

        $this->postJson('/api/client/bookings', $payload)->assertStatus(422);
    }

    public function test_coverage_area_notice_is_true_for_non_studio_locations(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $payload = $this->validPayload($photographer);
        $province = \App\Models\LocationProvince::create([
            'psgc_code' => '0506200000', // Sorsogon
            'name' => 'Test Province',
            'region_code' => '05',
        ]);
        $city = \App\Models\LocationCityMunicipality::create([
            'psgc_code' => '0506203000', // Bulan
            'province_id' => $province->id,
            'name' => 'Test Municipality',
            'type' => 'municipality',
        ]);
        $barangay = \App\Models\LocationBarangay::create([
            'psgc_code' => '0000000003',
            'city_municipality_id' => $city->id,
            'name' => 'Test Barangay',
        ]);

        $payload['location_type'] = 'outdoor_location';
        $payload['province_id'] = $province->id;
        $payload['city_municipality_id'] = $city->id;
        $payload['barangay_id'] = $barangay->id;
        $payload['event_address'] = '123 Test St';

        $response = $this->postJson('/api/client/bookings', $payload);

        $response->assertCreated()->assertJsonPath('data.coverage_area_notice', true);
    }
}