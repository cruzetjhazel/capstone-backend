<?php

namespace Tests\Feature\Booking;

use App\Models\AvailabilityWindow;
use App\Models\BookingBudgetRequest;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPaymentConfig;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A client can attach a budget offer when submitting a new booking.
 */
class CreateBookingWithBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function bookablePhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();
        PhotographerProfile::factory()->for($user)->complete()->create();
        PhotographerPortfolioImage::factory()->for($user)->count(6)->create();
        Package::factory()->for($user)->published()->create();
        PhotographerPaymentConfig::factory()->for($user)->create();

        AvailabilityWindow::factory()->for($user)->create([
            'date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        return $user;
    }

    private function payload(User $photographer, array $extra = []): array
    {
        return array_merge([
            'photographer_id' => $photographer->id,
            'package_id' => $photographer->packages()->first()->id,
            'event_type' => 'wedding',
            'event_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '09:00',
            'location_type' => 'studio',
        ], $extra);
    }

    public function test_booking_with_a_budget_below_the_listed_price_is_created_with_a_budget_request(): void
    {
        $photographer = $this->bookablePhotographer();
        $client = User::factory()->create();
        Sanctum::actingAs($client);
        $price = (float) $photographer->packages()->first()->price;

        $response = $this->postJson('/api/client/bookings', $this->payload($photographer, [
            'budget_amount' => $price - 100,
            'budget_note' => 'This is what I can afford.',
        ]));

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $bookingId = $response->json('data.id');

        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'client_id' => $client->id]);
        $this->assertSame(1, BookingBudgetRequest::where('booking_id', $bookingId)->count());
        $this->assertEquals($price - 100, (float) BookingBudgetRequest::where('booking_id', $bookingId)->value('budget_amount'));
    }

    public function test_booking_without_a_budget_creates_no_budget_request(): void
    {
        $photographer = $this->bookablePhotographer();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/client/bookings', $this->payload($photographer))->assertCreated();

        $this->assertSame(0, BookingBudgetRequest::count());
    }

    public function test_budget_equal_to_or_above_the_listed_price_is_rejected_and_no_booking_is_created(): void
    {
        $photographer = $this->bookablePhotographer();
        Sanctum::actingAs(User::factory()->create());
        $price = (float) $photographer->packages()->first()->price;

        $this->postJson('/api/client/bookings', $this->payload($photographer, ['budget_amount' => $price]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('budget_amount');

        $this->postJson('/api/client/bookings', $this->payload($photographer, ['budget_amount' => $price + 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('budget_amount');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(0, BookingBudgetRequest::count());
    }

    public function test_zero_or_negative_budget_is_rejected(): void
    {
        $photographer = $this->bookablePhotographer();
        Sanctum::actingAs(User::factory()->create());

        foreach ([0, -50] as $amount) {
            $this->postJson('/api/client/bookings', $this->payload($photographer, ['budget_amount' => $amount]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('budget_amount');
        }

        $this->assertDatabaseCount('bookings', 0);
    }
}