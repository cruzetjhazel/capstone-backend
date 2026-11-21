<?php

namespace Tests\Feature\Client;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

        public function test_client_can_deactivate_account_with_no_ongoing_bookings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/client/deactivate', ['confirmation' => 'DEACTIVATE', 'password' => 'password'])->assertOk();

        $this->assertSame('deactivated', $user->fresh()->account_status->value);
    }

    public function test_deactivation_requires_exact_confirmation_word(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/client/deactivate', ['confirmation' => 'deactivate', 'password' => 'password'])->assertStatus(422);
    }
    
    public function test_deactivation_is_blocked_with_ongoing_bookings(): void
    {
        $user = User::factory()->create();
        Booking::factory()->accepted()->create(['client_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/deactivate', ['confirmation' => 'DEACTIVATE', 'password' => 'password'])->assertStatus(422);
        $this->assertSame('active', $user->fresh()->account_status->value);
    }

    public function test_deactivation_is_allowed_when_bookings_are_resolved(): void
    {
        $user = User::factory()->create();
        Booking::factory()->rejected()->create(['client_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/deactivate', ['confirmation' => 'DEACTIVATE', 'password' => 'password'])->assertOk();
    }
    
    public function test_deactivation_is_rejected_with_wrong_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/client/deactivate', ['confirmation' => 'DEACTIVATE', 'password' => 'wrong-password'])
            ->assertStatus(422);
        $this->assertSame('active', $user->fresh()->account_status->value);
    }

    public function test_deactivation_requires_a_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/client/deactivate', ['confirmation' => 'DEACTIVATE'])->assertStatus(422);
        $this->assertSame('active', $user->fresh()->account_status->value);
    }
}