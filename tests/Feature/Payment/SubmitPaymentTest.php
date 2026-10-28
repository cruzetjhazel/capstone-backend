<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\PhotographerPaymentConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A client's GCash submission is ALWAYS saved as "awaiting the photographer's confirmation"
 * (see SubmitPaymentAction). Nothing is matched automatically: the booking only becomes
 * paid when the photographer verifies the payment. A GCash reference is exactly 13 digits.
 */
class SubmitPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function acceptedBooking(float $totalPrice = 10000): Booking
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerPaymentConfig::factory()->for($photographer)->create();
        $client = User::factory()->create();

        return Booking::factory()->accepted()->create([
            'photographer_id' => $photographer->id,
            'client_id' => $client->id,
            'total_price' => $totalPrice,
        ]);
    }

    protected function payload(string $reference, string $plan = 'full', float $amount = 10000, string $payer = 'Jane Doe'): array
    {
        return [
            'plan' => $plan,
            'reference_number' => $reference,
            'payer_name' => $payer,
            'amount' => $amount,
            'payment_date' => now()->format('Y-m-d'),
        ];
    }

    public function test_client_can_submit_full_payment_and_it_waits_for_the_photographer(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000001'))
            ->assertCreated()
            ->assertJsonPath('data.matching_status', 'not_matched');

        $fresh = $booking->fresh();
        $this->assertEquals('confirmed', $fresh->status->value);
        $this->assertEquals('pending_verification', $fresh->payment_status->value);
        $this->assertNull($fresh->service_status); // unverified payment must not settle or start the tracker
    }

    public function test_client_can_submit_half_payment_and_it_waits_for_the_photographer(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000002', 'half', 5000))
            ->assertCreated()
            ->assertJsonPath('data.matching_status', 'not_matched');

        $this->assertEquals('pending_verification', $booking->fresh()->payment_status->value);
    }

    public function test_verifying_a_full_payment_settles_the_booking(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);
        $paymentId = $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000003'))
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();

        $fresh = $booking->fresh();
        $this->assertEquals('confirmed', $fresh->status->value);
        $this->assertEquals('fully_paid', $fresh->payment_status->value);
    }

    public function test_verifying_a_half_payment_leaves_a_remaining_balance(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);
        $paymentId = $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000004', 'half', 5000))
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();

        $fresh = $booking->fresh();
        $this->assertEquals('confirmed', $fresh->status->value);
        $this->assertEquals('partially_paid', $fresh->payment_status->value);
        $this->assertEquals(5000.0, $fresh->remainingBalance());
    }

    public function test_amount_must_match_the_selected_plan(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000005', 'full', 8000))
            ->assertStatus(422);
    }

    public function test_a_reference_that_is_not_13_digits_is_rejected(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('GC-REF-1'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number']);
    }

    public function test_a_reference_already_verified_for_another_booking_cannot_be_used_again(): void
    {
        $first = $this->acceptedBooking(10000);
        Sanctum::actingAs($first->client);
        $paymentId = $this->postJson("/api/client/bookings/{$first->id}/payments", $this->payload('1000000000006'))
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($first->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();

        // A different booking tries to reuse the same reference number.
        $second = $this->acceptedBooking(10000);
        Sanctum::actingAs($second->client);

        $this->postJson("/api/client/bookings/{$second->id}/payments", $this->payload('1000000000006', 'full', 10000, 'John Roe'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number']);

        $this->assertNotSame('fully_paid', $second->fresh()->payment_status?->value);
    }

    public function test_a_reference_still_awaiting_review_on_another_booking_cannot_be_used_again(): void
    {
        $first = $this->acceptedBooking(10000);
        Sanctum::actingAs($first->client);
        $this->postJson("/api/client/bookings/{$first->id}/payments", $this->payload('1000000000007'))
            ->assertCreated(); // left pending on purpose

        $second = $this->acceptedBooking(10000);
        Sanctum::actingAs($second->client);

        $this->postJson("/api/client/bookings/{$second->id}/payments", $this->payload('1000000000007', 'full', 10000, 'John Roe'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number']);

        $this->assertDatabaseCount('payments', 1);
        $this->assertEquals('pending', $second->fresh()->payment_status->value);
    }

    public function test_a_rejected_reference_can_be_used_on_another_booking(): void
    {
        $first = $this->acceptedBooking(10000);
        Sanctum::actingAs($first->client);
        $paymentId = $this->postJson("/api/client/bookings/{$first->id}/payments", $this->payload('1000000000008'))
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($first->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/reject", ['notes' => 'Not in my GCash history.'])
            ->assertOk();

        $second = $this->acceptedBooking(10000);
        Sanctum::actingAs($second->client);

        $this->postJson("/api/client/bookings/{$second->id}/payments", $this->payload('1000000000008', 'full', 10000, 'John Roe'))
            ->assertCreated();
    }

    public function test_same_booking_can_resubmit_the_same_reference_after_a_rejection(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);
        $paymentId = $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000009'))
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/reject", ['notes' => 'Wrong amount shown.'])
            ->assertOk();

        Sanctum::actingAs($booking->client);
        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000009'))
            ->assertCreated();
    }

    public function test_cannot_pay_for_a_booking_that_is_not_accepted(): void
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerPaymentConfig::factory()->for($photographer)->create();
        $booking = Booking::factory()->create([ // default/pending status
            'photographer_id' => $photographer->id,
            'total_price' => 10000,
        ]);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000007'))
            ->assertStatus(422);
    }

    public function test_cannot_submit_payment_twice_for_the_same_booking(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->client);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000008'))->assertCreated();
        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000009'))->assertStatus(422);
    }

    public function test_another_client_cannot_submit_payment_for_someone_elses_booking(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000010'))
            ->assertStatus(403);
    }

    public function test_photographer_cannot_submit_a_client_payment(): void
    {
        $booking = $this->acceptedBooking(10000);
        Sanctum::actingAs($booking->photographer);

        $this->postJson("/api/client/bookings/{$booking->id}/payments", $this->payload('1000000000011'))
            ->assertStatus(403);
    }
}