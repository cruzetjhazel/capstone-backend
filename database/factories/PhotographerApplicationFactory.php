<?php

namespace Database\Factories;

use App\Enums\PhotographerApplicationStatus;
use App\Enums\PhotographerType;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PhotographerApplicationFactory extends Factory
{
    protected $model = PhotographerApplication::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->photographer(),
            'photographer_type' => PhotographerType::Freelancer,
            'is_freelancer' => true,
            'is_studio_owner' => false,
            'status' => PhotographerApplicationStatus::Draft,
        ];
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'business_name' => fake()->company(),
            'location' => 'Bulan, Sorsogon',
            'years_active' => fake()->numberBetween(1, 15),
            'services' => ['Wedding', 'Portrait'],
            'coverage_area' => 'bulan_only',
            'shooting_types' => ['indoor', 'outdoor'],
            'price_min' => 3000,
            'price_max' => 15000,
            'government_id_path' => 'verification-documents/fake/gov_id.jpg',
            'selfie_with_id_path' => 'verification-documents/fake/selfie.jpg',
        ]);
    }

    public function pendingReview(): static
    {
        return $this->complete()->state(fn () => [
            'status' => PhotographerApplicationStatus::PendingReview,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->pendingReview()->state(fn () => [
            'status' => PhotographerApplicationStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->pendingReview()->state(fn () => [
            'status' => PhotographerApplicationStatus::Rejected,
            'reviewed_at' => now(),
            'rejection_reason' => 'Incomplete verification documents.',
        ]);
    }

    public function revisionRequested(): static
    {
        return $this->pendingReview()->state(fn () => [
            'status' => PhotographerApplicationStatus::RevisionRequested,
            'reviewed_at' => now(),
            'revision_notes' => 'Please re-upload a clearer government ID.',
        ]);
    }

    public function studio(): static
    {
        return $this->state(fn () => [
            'photographer_type' => PhotographerType::Studio,
            'is_freelancer' => false,
            'is_studio_owner' => true,
            'team_size' => fake()->numberBetween(2, 10),
        ]);
    }
}