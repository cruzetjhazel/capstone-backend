<?php

namespace Tests\Feature;

use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotographerApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function photographerWithDraft(): User
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->create();

        return $user;
    }

    public function test_photographer_can_view_their_own_application(): void
    {
        $user = $this->photographerWithDraft();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/photographer/application');

        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_client_cannot_access_photographer_application_endpoints(): void
    {
        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $this->getJson('/api/photographer/application')->assertStatus(403);
    }

    public function test_photographer_can_update_draft_application(): void
    {
        Storage::fake('local');
        $user = $this->photographerWithDraft();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/photographer/application', [
            'business_name' => 'Alex Photography',
            'location' => 'Bulan, Sorsogon',
            'years_active' => 3,
            'services' => ['Wedding', 'Portrait'],
            'coverage_area' => 'bulan_only',
            'shooting_types' => ['hybrid'],
            'price_min' => 3000,
            'price_max' => 12000,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('photographer_applications', [
            'user_id' => $user->id,
            'business_name' => 'Alex Photography',
        ]);

        $application = $user->photographerApplication->fresh();
        $this->assertContains('indoor', $application->shooting_types);
        $this->assertContains('outdoor', $application->shooting_types);
    }

    public function test_submit_fails_when_required_fields_are_missing(): void
    {
        $user = $this->photographerWithDraft();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/photographer/application/submit');

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_submit_succeeds_when_application_is_complete(): void
    {
        Storage::fake('local');
        $user = User::factory()->photographer()->create();
        $application = PhotographerApplication::factory()->for($user)->complete()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/photographer/application/submit');

        $response->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->assertNotNull($application->fresh()->submitted_at);
    }

        public function test_studio_application_can_submit_without_business_permit(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->studio()->complete()->create([
            'business_permit_path' => null,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/photographer/application/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');
    }

    public function test_cannot_edit_application_once_pending_review(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->pendingReview()->create();

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/photographer/application', ['business_name' => 'New Name']);

        $response->assertStatus(422);
    }

    public function test_can_edit_and_resubmit_after_revision_requested(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->revisionRequested()->create();

        Sanctum::actingAs($user);

        $this->patchJson('/api/photographer/application', ['business_name' => 'Updated Name'])
            ->assertOk();

        $this->postJson('/api/photographer/application/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');
    }

    public function test_reapply_after_rejection_resets_to_draft_when_allowed(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->rejected()->create(['can_reapply' => true]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/photographer/application/reapply');

        $response->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_reapply_is_blocked_when_not_allowed(): void
    {
        $user = User::factory()->photographer()->create();
        PhotographerApplication::factory()->for($user)->rejected()->create(['can_reapply' => false]);

        Sanctum::actingAs($user);

        $this->postJson('/api/photographer/application/reapply')->assertStatus(422);
    }
}