<?php

namespace Tests\Feature\Photographer;

use App\Models\CustomPackageComponent;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    public function test_photographer_can_configure_custom_package_pricing(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true,
            'base_fee' => 2000,
        ]);

        $response->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertDatabaseHas('custom_package_configs', ['user_id' => $user->id, 'enabled' => true]);
    }

    public function test_invalid_custom_pricing_is_rejected(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        // enabled=true but no base_fee provided
        $this->patchJson('/api/photographer/custom-package/config', ['enabled' => true])->assertStatus(422);
    }

    public function test_custom_pricing_can_be_retrieved_by_the_photographer(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->patchJson('/api/photographer/custom-package/config', ['enabled' => true, 'base_fee' => 2000]);

        $this->getJson('/api/photographer/custom-package/config')
            ->assertOk()
            ->assertJsonPath('data.base_fee', '2000.00');
    }

    public function test_custom_package_max_sessions_is_capped_at_15(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'base_fee' => 2000, 'allows_multiple_sessions' => true, 'max_sessions' => 15,
        ])->assertOk();

        $this->patchJson('/api/photographer/custom-package/config', [
            'enabled' => true, 'base_fee' => 2000, 'allows_multiple_sessions' => true, 'max_sessions' => 16,
        ])->assertStatus(422);
    }

    public function test_photographer_can_create_a_custom_package_component(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/photographer/custom-package/components', [
            'type' => 'photo_count_tier',
            'type' => 'tier_option',
            'tier_name' => 'Photo Count',
            'label' => '100 Photos',
            'price_addition' => 1000,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('custom_package_components', ['user_id' => $user->id, 'label' => '100 Photos']);
    }

    public function test_component_type_must_be_valid(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $this->postJson('/api/photographer/custom-package/components', [
            'type' => 'not_a_real_type',
            'label' => 'Bad',
            'price_addition' => 100,
        ])->assertStatus(422);
    }

    public function test_photographer_can_archive_and_restore_a_component(): void
    {
        $user = $this->approvedPhotographer();
        $component = CustomPackageComponent::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/photographer/custom-package/components/{$component->id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'archived');

        $this->postJson("/api/photographer/custom-package/components/{$component->id}/restore")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_photographer_cannot_manage_another_photographers_component(): void
    {
        $owner = $this->approvedPhotographer();
        $component = CustomPackageComponent::factory()->for($owner)->create();

        $other = $this->approvedPhotographer();
        Sanctum::actingAs($other);

        $this->postJson("/api/photographer/custom-package/components/{$component->id}/archive")->assertStatus(403);
    }

    public function test_client_cannot_configure_custom_pricing(): void
    {
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->patchJson('/api/photographer/custom-package/config', ['enabled' => true, 'base_fee' => 1000])
            ->assertStatus(403);
    }
}