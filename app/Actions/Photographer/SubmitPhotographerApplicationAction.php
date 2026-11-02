<?php

namespace App\Actions\Photographer;

use App\Enums\PhotographerApplicationStatus;
use App\Models\PhotographerApplication;
use Illuminate\Validation\ValidationException;

class SubmitPhotographerApplicationAction
{
    public function execute(PhotographerApplication $application): PhotographerApplication
    {
        if (! $application->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['This application cannot be submitted from its current status.'],
            ]);
        }

        $missing = $this->missingFields($application);

        if (! empty($missing)) {
            throw ValidationException::withMessages(['application' => $missing]);
        }

        $application->fill([
            'status' => PhotographerApplicationStatus::PendingReview,
            'submitted_at' => now(),
            'rejection_reason' => null,
            'revision_notes' => null,
        ])->save();

        return $application->fresh();
    }

    protected function missingFields(PhotographerApplication $application): array
    {
        $missing = [];

        $required = [
            'business_name', 'location', 'years_active',
            'services', 'coverage_area', 'shooting_types',
            'price_min', 'price_max',
            'government_id_path', 'selfie_with_id_path',
        ];

        foreach ($required as $field) {
            if (empty($application->{$field})) {
                $missing[] = "The {$field} field is required before submission.";
            }
        }

        // business_permit_path is intentionally NOT required, not even for Studio
        // applications: the DTI / business permit upload is optional.

        return $missing;
    }
}