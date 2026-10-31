<?php

namespace Database\Factories;

use App\Enums\PackageStatus;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\PackageScheduleMode;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->photographer(),
            'name' => 'Basic Wedding Package',
            'description' => 'Professional photography session with edited photos.',
            'included_items' => ['8 hours coverage', '300 edited photos', 'Online gallery'],
            'price' => 10000,
            'duration_minutes' => 480,
            'buffer_minutes' => 30,
            'schedule_mode' => PackageScheduleMode::Timed,
            'status' => PackageStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => PackageStatus::Published]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => PackageStatus::Archived]);
    }
        public function open(): static
    {
        return $this->state(fn () => [
            'schedule_mode' => PackageScheduleMode::Open,
            'duration_minutes' => null,
        ]);
    }
}