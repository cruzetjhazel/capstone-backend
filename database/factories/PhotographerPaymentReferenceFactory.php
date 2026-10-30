<?php

namespace Database\Factories;

use App\Enums\PaymentType;
use App\Enums\PhotographerPaymentReferenceStatus;
use App\Models\PhotographerPaymentReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PhotographerPaymentReferenceFactory extends Factory
{
    protected $model = PhotographerPaymentReference::class;

    public function definition(): array
    {
        return [
            'photographer_id' => User::factory()->photographer(),
            'reference_number' => fake()->numerify('#############'),
            'amount_received' => 5000,
            'payment_date' => now()->format('Y-m-d'),
            'payment_type' => PaymentType::Online,
            'status' => PhotographerPaymentReferenceStatus::Available,
        ];
    }

    public function used(): static
    {
        return $this->state(fn () => ['status' => PhotographerPaymentReferenceStatus::Used]);
    }

    public function invalidated(): static
    {
        return $this->state(fn () => ['status' => PhotographerPaymentReferenceStatus::Invalidated]);
    }
}
