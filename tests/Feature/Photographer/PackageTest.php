<?php

namespace Tests\Feature\Photographer;

use App\Enums\PackageStatus;
use App\Models\Package;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedPhotographer(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->approved()->create();

        return $user;
    }

    protected function validPayload(): array
    {
        return [
            'name' => 'Basic Wedding Package',
            'description' => 'Wedding photography coverage',
            'included_items' => [
                ['name' => 'Coverage', 'detail' => '8 hours'],
                ['name' => 'Edited photos', 'detail' => '300 photos'],
            ],
            'price' => 10000,
            'duration_minutes' => 480,
            'buffer_minutes' => 30,
        ];
    }

    public function test_eligible_photographer_can_create_a_package(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/photographer/packages', $this->validPayload());

        $response->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('packages', ['user_id' => $user->id, 'name' => 'Basic Wedding Package']);
    }

    public function test_unapproved_photographer_cannot_create_a_package(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->pendingReview()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/photographer/packages', $this->validPayload())->assertStatus(403);
    }

    public function test_client_cannot_create_packages(): void
    {
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->postJson('/api/photographer/packages', $this->validPayload())->assertStatus(403);
    }

    public function test_price_validation(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $payload = $this->validPayload();
        $payload['price'] = -5;

        $this->postJson('/api/photographer/packages', $payload)->assertStatus(422);
    }

    public function test_price_of_zero_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $payload = $this->validPayload();
        $payload['name'] = 'Free package';
        $payload['price'] = 0;

        $this->postJson('/api/photographer/packages', $payload)->assertStatus(422);
        $this->assertDatabaseMissing('packages', ['name' => 'Free package']);
    }

    public function test_max_sessions_can_be_up_to_15_but_not_more(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $allowed = $this->validPayload();
        $allowed['allows_multiple_sessions'] = true;
        $allowed['max_sessions'] = 15;
        $this->postJson('/api/photographer/packages', $allowed)->assertCreated();

        $tooMany = $this->validPayload();
        $tooMany['name'] = 'Too many sessions';
        $tooMany['allows_multiple_sessions'] = true;
        $tooMany['max_sessions'] = 16;
        $this->postJson('/api/photographer/packages', $tooMany)->assertStatus(422);
        $this->assertDatabaseMissing('packages', ['name' => 'Too many sessions']);
    }

    public function test_outdoor_price_of_zero_is_rejected(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $payload = $this->validPayload();
        $payload['name'] = 'Free outdoor package';
        $payload['outdoor_price'] = 0;

        $this->postJson('/api/photographer/packages', $payload)->assertStatus(422);
        $this->assertDatabaseMissing('packages', ['name' => 'Free outdoor package']);
    }

    public function test_a_positive_outdoor_price_is_accepted(): void
    {
        Sanctum::actingAs($this->approvedPhotographer());

        $payload = $this->validPayload();
        $payload['outdoor_price'] = 12000;

        $this->postJson('/api/photographer/packages', $payload)->assertCreated();
    }

    public function test_duration_validation(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $payload = $this->validPayload();
        $payload['duration_minutes'] = 0;

        $this->postJson('/api/photographer/packages', $payload)->assertStatus(422);
    }

    public function test_buffer_validation(): void
    {
        $user = $this->approvedPhotographer();
        Sanctum::actingAs($user);

        $payload = $this->validPayload();
        $payload['buffer_minutes'] = -1;

        $this->postJson('/api/photographer/packages', $payload)->assertStatus(422);
    }

    public function test_photographer_can_view_their_own_packages(): void
    {
        $user = $this->approvedPhotographer();
        Package::factory()->for($user)->count(2)->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/photographer/packages');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_photographer_cannot_manage_another_photographers_package(): void
    {
        $owner = $this->approvedPhotographer();
        $package = Package::factory()->for($owner)->create();

        $other = $this->approvedPhotographer();
        Sanctum::actingAs($other);

        $this->patchJson("/api/photographer/packages/{$package->id}", $this->validPayload())->assertStatus(403);
        $this->postJson("/api/photographer/packages/{$package->id}/publish")->assertStatus(403);
    }

    public function test_photographer_can_update_a_draft_package(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $payload = $this->validPayload();
        $payload['name'] = 'Updated Package Name';

        $this->patchJson("/api/photographer/packages/{$package->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Package Name');
    }

    public function test_publish_and_revert_to_draft_lifecycle(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/photographer/packages/{$package->id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published');

        $this->postJson("/api/photographer/packages/{$package->id}/revert-to-draft")
            ->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_cannot_publish_an_already_published_package(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->published()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/photographer/packages/{$package->id}/publish")->assertStatus(422);
    }

    public function test_photographer_can_archive_and_restore_a_package(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->published()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/photographer/packages/{$package->id}/archive")
            ->assertOk()->assertJsonPath('data.status', 'archived');

        $this->postJson("/api/photographer/packages/{$package->id}/restore")
            ->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_cannot_edit_an_archived_package(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->archived()->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/photographer/packages/{$package->id}", $this->validPayload())->assertStatus(422);
    }

    public function test_can_permanently_delete_an_archived_package(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->archived()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/photographer/packages/{$package->id}")->assertOk();
        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
    }

    public function test_cannot_permanently_delete_a_non_archived_package(): void
    {
        $user = $this->approvedPhotographer();
        $package = Package::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/photographer/packages/{$package->id}")->assertStatus(422);
        $this->assertDatabaseHas('packages', ['id' => $package->id]);
    }

    public function test_active_package_count_and_has_active_package(): void
    {
        $user = $this->approvedPhotographer();
        Package::factory()->for($user)->published()->count(2)->create();
        Package::factory()->for($user)->create(); // draft, not counted

        $this->assertSame(2, $user->activePackageCount());
        $this->assertTrue($user->hasActivePackage());
    }

    public function test_has_active_package_is_false_with_no_published_packages(): void
    {
        $user = $this->approvedPhotographer();
        Package::factory()->for($user)->create(); // draft only

        $this->assertFalse($user->hasActivePackage());
    }
}