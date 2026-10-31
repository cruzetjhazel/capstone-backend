<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PhotographerPaymentConfig;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fills the gaps between the Payments test-case table (PAY-xx) and the existing tests. */
class PaymentCaseCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function acceptedBooking(float $totalPrice = 10000): Booking
    {
        $photographer = User::factory()->photographer()->create();
        PhotographerPaymentConfig::factory()->for($photographer)->create();

        return Booking::factory()->accepted()->create([
            'photographer_id' => $photographer->id,
            'client_id' => User::factory()->create()->id,
            'total_price' => $totalPrice,
        ]);
    }

    protected function payload(string $reference, string $plan = 'full', float $amount = 10000): array
    {
        return [
            'plan' => $plan,
            'reference_number' => $reference,
            'payer_name' => 'Jane Doe',
            'amount' => $amount,
            'payment_date' => now()->format('Y-m-d'),
        ];
    }

    protected function submit(Booking $booking, array $payload)
    {
        Sanctum::actingAs($booking->client);

        return $this->postJson("/api/client/bookings/{$booking->id}/payments", $payload);
    }

    // PAY-02
    public function test_pay_02_half_payment_is_recorded_as_50_percent_and_awaits_review(): void
    {
        $booking = $this->acceptedBooking(10000);

        $this->submit($booking, $this->payload('1000000000101', 'half', 5000))->assertCreated();

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'plan' => 'half',
            'amount' => 5000,
            'matching_status' => 'not_matched',
        ]);
    }

    // PAY-02
    public function test_pay_02_future_payment_date_is_rejected(): void
    {
        $booking = $this->acceptedBooking(10000);
        $payload = $this->payload('1000000000102', 'half', 5000);
        $payload['payment_date'] = now()->addDay()->format('Y-m-d');

        $this->submit($booking, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date']);

        $this->assertDatabaseCount('payments', 0);
    }

    // PAY-02
    public function test_reference_with_spaces_is_stored_as_digits_only(): void
    {
        $booking = $this->acceptedBooking(10000);

        $this->submit($booking, $this->payload('1000 000 000 123'))->assertCreated();

        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'reference_number' => '1000000000123']);
    }

    // PAY-02
    public function test_reference_with_letters_or_wrong_length_is_rejected(): void
    {
        $booking = $this->acceptedBooking(10000);

        foreach (['10000000001AB', '123456789012', '12345678901234'] as $bad) {
            $this->submit($booking, $this->payload($bad))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['reference_number']);
        }
    }

    // PAY-02
    public function test_reference_is_required(): void
    {
        $booking = $this->acceptedBooking(10000);

        $this->submit($booking, $this->payload(''))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_number']);
    }

    // PAY-07
    public function test_pay_07_reject_needs_notes_then_reopens_the_booking_for_a_new_submission(): void
    {
        $booking = $this->acceptedBooking(10000);
        $paymentId = $this->submit($booking, $this->payload('1000000000103'))->assertCreated()->json('data.id');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);

        $this->postJson("/api/photographer/payments/{$paymentId}/reject", ['notes' => 'Not in my GCash history.'])
            ->assertOk()
            ->assertJsonPath('data.matching_status', 'rejected');

        $this->assertEquals('pending', $booking->fresh()->payment_status->value);

        // The client may submit again.
        $this->submit($booking, $this->payload('1000000000104'))->assertCreated();
    }

    // PAY-07
    public function test_pay_07_another_photographer_cannot_reject_the_payment(): void
    {
        $booking = $this->acceptedBooking(10000);
        $paymentId = $this->submit($booking, $this->payload('1000000000105'))->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->photographer()->create());
        $this->postJson("/api/photographer/payments/{$paymentId}/reject", ['notes' => 'x'])->assertStatus(403);
    }

    // PAY-11
    public function test_pay_11_after_half_payment_is_verified_a_second_online_payment_is_refused_and_balance_is_settled_onsite(): void
    {
        $booking = $this->acceptedBooking(10000);
        $paymentId = $this->submit($booking, $this->payload('1000000000106', 'half', 5000))->assertCreated()->json('data.id');

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/payments/{$paymentId}/verify")->assertOk();
        $this->assertEquals('partially_paid', $booking->fresh()->payment_status->value);

        $this->submit($booking, $this->payload('1000000000107', 'half', 5000))->assertStatus(422);

        Sanctum::actingAs($booking->photographer);
        $this->postJson("/api/photographer/bookings/{$booking->id}/payments/onsite", [
            'amount' => 5000,
            'payment_date' => now()->format('Y-m-d'),
        ])->assertOk()->assertJsonPath('data.booking.payment_status', 'fully_paid');
    }

    // PAY-08
    public function test_database_refuses_two_active_payments_with_the_same_reference(): void
    {
        $first = $this->acceptedBooking(10000);
        $second = $this->acceptedBooking(10000);
        $this->submit($first, $this->payload('1000000000201'))->assertCreated();

        $this->expectException(QueryException::class);

        Payment::create([
            'booking_id' => $second->id,
            'client_id' => $second->client_id,
            'photographer_id' => $second->photographer_id,
            'type' => 'online',
            'method' => 'gcash',
            'plan' => 'full',
            'amount' => 10000,
            'reference_number' => '1000000000201',
            'active_reference_number' => '1000000000201',
            'payer_name' => 'John Roe',
            'payment_date' => now()->format('Y-m-d'),
            'matching_status' => 'not_matched',
        ]);
    }
}