<?php

namespace Database\Factories;

use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'client_id' => User::factory(),
            'photographer_id' => User::factory()->photographer(),
            'type' => PaymentType::Online,
            'method' => 'gcash',
            'plan' => PaymentPlan::Full,
            'amount' => 5000,
            'reference_number' => fake()->numerify('#############'),
            'payer_name' => fake()->name(),
            'payment_date' => now()->format('Y-m-d'),
            'notes' => null,
            'matching_status' => PaymentMatchingStatus::Submitted,
        ];
    }

    public function onsite(): static
    {
        return $this->state(fn () => [
            'type' => PaymentType::Onsite,
            'method' => 'cash',
            'reference_number' => null,
        ]);
    }

    public function matched(): static
    {
        return $this->state(fn () => [
            'matching_status' => PaymentMatchingStatus::Matched,
        ]);
    }

    public function manuallyVerified(User $verifier): static
    {
        return $this->state(fn () => [
            'matching_status' => PaymentMatchingStatus::ManuallyVerified,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_action' => 'verified',
            'verification_notes' => 'Confirmed against GCash statement.',
        ]);
    }

    public function pendingMatch(): static
    {
        return $this->state(fn () => [
            'matching_status' => PaymentMatchingStatus::PendingMatch,
        ]);
    }

    public function notMatched(): static
    {
        return $this->state(fn () => [
            'matching_status' => PaymentMatchingStatus::NotMatched,
        ]);
    }
}
