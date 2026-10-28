<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnsitePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function halfPaidBooking(float $totalPrice = 10000): Booking
    {
        $photographer = User::factory()->photographer()->create();
        $client = User::factory()->create();

        $booking = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'status' => \App\Enums\BookingStatus::Confirmed,
            'payment_plan' => 'half',
            'payment_status' => 'partially_paid',
            'total_price' => $totalPrice,
        ]);

        // The online half was already submitted to get the booking to
        // Confirmed/partially_paid in the first place — record it, or
        // remainingBalance() has nothing to subtract from total_price.
        \App\Models\Payment::create([
            'booking_id' => $booking->id,
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'type' => 'online',
            'method' => 'gcash',
            'plan' => 'half',
            'amount' => $totalPrice / 2,
            'reference_number' => 'GC-FIXTURE-'.$booking->id,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        return $booking;
    }

    public function test_photographer_can_record_onsite_remaining_balance(): void
    {
        $booking = $this->halfPaidBooking(10000);
        Sanctum::actingAs($booking->photographer);

        $response = $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertOk()->assertJsonPath('data.booking.payment_status', 'fully_paid');
        $this->assertEquals('fully_paid', $booking->fresh()->payment_status->value);
    }

    public function test_photographer_can_record_a_partial_onsite_payment(): void
    {
        $booking = $this->halfPaidBooking(10000);
        Sanctum::actingAs($booking->photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 4000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertOk()
            ->assertJsonPath('data.booking.payment_status', 'partially_paid')
            ->assertJsonPath('data.booking.remaining_balance', 1000);

        $this->assertEquals('partially_paid', $booking->fresh()->payment_status->value);
    }

    public function test_amount_cannot_exceed_the_remaining_balance(): void
    {
        $booking = $this->halfPaidBooking(10000);
        Sanctum::actingAs($booking->photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 6000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(422);
    }

    public function test_cannot_record_onsite_payment_when_fully_paid_already(): void
    {
        $booking = $this->halfPaidBooking(10000);
        $booking->update(['payment_status' => 'fully_paid']);
        Sanctum::actingAs($booking->photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(422);
    }

    public function test_cannot_record_onsite_payment_for_a_full_payment_plan_booking(): void
    {
        $photographer = User::factory()->photographer()->create();
        $booking = Booking::factory()->create([
            'photographer_id' => $photographer->id,
            'status' => \App\Enums\BookingStatus::Confirmed,
            'payment_plan' => 'full',
            'payment_status' => 'fully_paid',
            'total_price' => 10000,
        ]);
        Sanctum::actingAs($photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 1,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(422);
    }

    public function test_other_photographer_cannot_record_onsite_payment(): void
    {
        $booking = $this->halfPaidBooking(10000);
        $otherPhotographer = User::factory()->photographer()->create();
        Sanctum::actingAs($otherPhotographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(403);
    }

    public function test_onsite_payment_date_cannot_be_in_the_future(): void
    {
        $booking = $this->halfPaidBooking(10000);
        Sanctum::actingAs($booking->photographer);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000,
            'payment_date' => now()->addDay()->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_date']);
    }

    public function test_client_cannot_record_onsite_payment(): void
    {
        $booking = $this->halfPaidBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertStatus(403);
    }
}