<?php

namespace App\Actions\Photographer;

use App\Models\PhotographerApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UpdatePhotographerApplicationAction
{
    public function execute(PhotographerApplication $application, array $data, array $files = []): PhotographerApplication
    {
        $hasDocumentFiles = ($files['government_id'] ?? null) instanceof UploadedFile
            || ($files['selfie_with_id'] ?? null) instanceof UploadedFile
            || ($files['business_permit'] ?? null) instanceof UploadedFile
            || ! empty($files['additional_documents']);

        // Verification documents stay locked to the Draft/RevisionRequested
        // intake flow even after approval — swapping a government ID or
        // permit post-approval should go through re-review, not a silent
        // Settings-page save.
        if ($hasDocumentFiles && ! $application->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Verification documents can only be changed while your application is in draft or revision-requested status.'],
            ]);
        }

        // Plain business details (name, location, prices, coverage, etc.)
        // are also editable once Approved — this is what the Studio
        // Settings page saves, and it shouldn't require re-entering review.
        if (! $hasDocumentFiles && ! $application->canUpdateBusinessDetails()) {
            throw ValidationException::withMessages([
                'status' => ['This application can no longer be edited in its current status.'],
            ]);
        }

        $fields = collect($data)->only([
            'is_freelancer', 'is_studio_owner',
            'business_name', 'location', 'years_active', 'team_size',
            'services', 'other_services', 'coverage_area', 'shooting_types',
            'price_min', 'price_max',
        ])->toArray();

        if (array_key_exists('is_freelancer', $fields) || array_key_exists('is_studio_owner', $fields)) {
            $isFreelancer = (bool) ($fields['is_freelancer'] ?? $application->is_freelancer);
            $isStudioOwner = (bool) ($fields['is_studio_owner'] ?? $application->is_studio_owner);

            if (! $isFreelancer && ! $isStudioOwner) {
                throw ValidationException::withMessages([
                    'is_freelancer' => ['Select at least one: Freelance Photographer or Studio Owner.'],
                ]);
            }

            // Keep the legacy single column in sync, same rule as RegisterPhotographerAction.
            $fields['photographer_type'] = $isStudioOwner ? PhotographerType::Studio : PhotographerType::Freelancer;
        }

        if (! empty($fields['shooting_types']) && in_array('hybrid', $fields['shooting_types'], true)) {
            $fields['shooting_types'] = array_values(array_unique(array_merge(
                $fields['shooting_types'],
                ['indoor', 'outdoor']
            )));
        }

        if (array_key_exists('government_id', $files) && $files['government_id'] instanceof UploadedFile) {
            $fields['government_id_path'] = $this->store($application, $files['government_id']);
        }

        if (array_key_exists('selfie_with_id', $files) && $files['selfie_with_id'] instanceof UploadedFile) {
            $fields['selfie_with_id_path'] = $this->store($application, $files['selfie_with_id']);
        }

        if (array_key_exists('business_permit', $files) && $files['business_permit'] instanceof UploadedFile) {
            $fields['business_permit_path'] = $this->store($application, $files['business_permit']);
        }

        if (! empty($files['additional_documents'])) {
            $paths = $application->additional_document_paths ?? [];
            foreach ($files['additional_documents'] as $file) {
                if ($file instanceof UploadedFile) {
                    $paths[] = $this->store($application, $file);
                }
            }
            $fields['additional_document_paths'] = $paths;
        }

        $application->fill($fields)->save();

        return $application->fresh();
    }

    protected function store(PhotographerApplication $application, UploadedFile $file): string
    {
        return $file->store("verification-documents/{$application->user_id}", 'local');
    }
}