<?php

namespace App\Http\Resources;

use App\Actions\Photographer\BuildApplicationChecklistAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhotographerApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdministrator() ?? false;

        return [
            'id' => $this->id,
            'photographer_type' => $this->photographer_type->value,
            'is_freelancer' => (bool) $this->is_freelancer,
            'is_studio_owner' => (bool) $this->is_studio_owner,
            'status' => $this->status->value,
            'business_name' => $this->business_name,
            'location' => $this->location,
            'years_active' => $this->years_active,
            'freelance_years_experience' => $this->freelance_years_experience,
            'team_size' => $this->team_size,
            'services' => $this->services,
            'other_services' => $this->other_services,
            'coverage_area' => $this->coverage_area,
            'shooting_types' => $this->shooting_types,
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'documents_submitted' => [
                'government_id' => ! empty($this->government_id_path),
                'selfie_with_id' => ! empty($this->selfie_with_id_path),
                'business_permit' => ! empty($this->business_permit_path),
                'additional_documents' => count($this->additional_document_paths ?? []),
            ],
            'submitted_at' => $this->submitted_at,
            'revision_notes' => $this->revision_notes,
            'rejection_reason' => $this->rejection_reason,
            'can_reapply' => $this->can_reapply,
            $this->mergeWhen($isAdmin, [
                'applicant' => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'account_status' => $this->user->account_status->value,
                ],
                'reviewed_by' => $this->reviewer?->id,
                'reviewed_at' => $this->reviewed_at,
            ]),
            // Only built for the admin detail view (index doesn't load itemReviews).
            $this->mergeWhen($isAdmin && $this->relationLoaded('itemReviews'), fn () => [
                'checklist' => app(BuildApplicationChecklistAction::class)->execute($this->resource),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}