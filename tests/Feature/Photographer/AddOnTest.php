<?php

namespace Tests\Feature\Photographer;

use App\Models\AddOn;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddOnTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_eligible_photographer_can_create_an_add_on(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/photographer/add-ons', [
            'name' => 'Extra Hour Coverage',
            'description' => 'One additional hour',
            'price' => 1500,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('add_ons', ['user_id' => $user->id, 'name' => 'Extra Hour Coverage']);
    }

    public function test_client_cannot_create_add_ons(): void
    {
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/photographer/add-ons', ['name' => 'X', 'price' => 100])->assertStatus(403);
    }

    public function test_photographer_cannot_manage_another_photographers_add_on(): void
    {
        $owner = $this->approvedPhotographer();
        $addOn = AddOn::factory()->for($owner)->create();

        $other = $this->approvedPhotographer();
        Sanctum::actingAs($other);

        $this->patchJson("/api/photographer/add-ons/{$addOn->id}", ['name' => 'Hijacked', 'price' => 1])->assertStatus(403);
    }

    public function test_add_on_validation(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->postJson('/api/photographer/add-ons', ['name' => '', 'price' => -1])->assertStatus(422);
    }

    public function test_add_on_price_of_zero_is_allowed(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->postJson('/api/photographer/add-ons', ['name' => 'Free extra print', 'price' => 0])->assertCreated();
        $this->assertDatabaseHas('add_ons', ['user_id' => $user->id, 'name' => 'Free extra print']);
    }

    public function test_add_on_without_a_name_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->postJson('/api/photographer/add-ons', ['name' => '', 'price' => 500])->assertStatus(422);
    }

    public function test_add_on_with_a_negative_price_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->postJson('/api/photographer/add-ons', ['name' => 'Negative extra', 'price' => -1])->assertStatus(422);
    }

    public function test_photographer_can_update_an_add_on(): void
    {
        $user = $this->approvedPhotographer();
        $addOn = AddOn::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/photographer/add-ons/{$addOn->id}", ['name' => 'Updated', 'price' => 2000])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated');
    }

    public function test_photographer_can_archive_and_restore_an_add_on(): void
    {
        $user = $this->approvedPhotographer();
        $addOn = AddOn::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/photographer/add-ons/{$addOn->id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'archived');

        $this->postJson("/api/photographer/add-ons/{$addOn->id}/restore")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_can_permanently_delete_an_archived_add_on(): void
    {
        $user = $this->approvedPhotographer();
        $addOn = AddOn::factory()->for($user)->archived()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/photographer/add-ons/{$addOn->id}")->assertOk();
        $this->assertDatabaseMissing('add_ons', ['id' => $addOn->id]);
    }

    public function test_cannot_permanently_delete_an_active_add_on(): void
    {
        $user = $this->approvedPhotographer();
        $addOn = AddOn::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/photographer/add-ons/{$addOn->id}")->assertStatus(422);
    }
}