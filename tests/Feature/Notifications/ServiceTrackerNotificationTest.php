<?php

namespace Tests\Feature\Notifications;

use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceTrackerNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_automatic_event_day_transition_notifies_the_client(): void
    {
        $photographer = $this->approvedPhotographer();
        $booking = Booking::factory()->confirmed()->create([
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
        ]);
        Sanctum::actingAs($photographer);

        app(\App\Actions\Booking\RunServiceProgressTransitionsAction::class)->execute();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $booking->client_id,
            'type' => \App\Notifications\Booking\ServiceTrackerUpdatedNotification::class,
        ]);
    }
}