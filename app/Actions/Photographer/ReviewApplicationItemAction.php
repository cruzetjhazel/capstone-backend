<?php

namespace App\Actions\Photographer;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\PhotographerApplicationStatus;
use App\Enums\PortfolioImageStatus;
use App\Models\ApplicationItemReview;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPortfolioImage;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReviewApplicationItemAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(PhotographerApplication $application, User $admin, string $type, string $ref, string $status, ?string $feedback): ApplicationItemReview
    {
        if ($application->status !== PhotographerApplicationStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => ['Items can only be reviewed while the application is Pending Review.'],
            ]);
        }

        [$key, $path, $label] = $this->resolve($application, $type, $ref);

        $review = ApplicationItemReview::updateOrCreate(
            ['photographer_application_id' => $application->id, 'item_type' => $type, 'item_key' => $key],
            [
                'file_path' => $path,
                'status' => $status,
                'feedback' => $status === 'needs_revision' ? $feedback : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]
        );

        $verb = match ($status) {
            'reviewed' => 'Marked as reviewed',
            'needs_revision' => 'Flagged for revision',
            default => 'Reset to pending',
        };

        $this->activityLogger->execute(
            causer: $admin,
            subject: $application,
            action: 'application.item_reviewed',
            description: "{$verb}: {$label} on photographer application #{$application->id}",
            metadata: ['item_type' => $type, 'item_key' => $key, 'status' => $status, 'feedback' => $feedback],
        );

        return $review;
    }

    private function resolve(PhotographerApplication $application, string $type, string $ref): array
    {
        $single = [
            'government_id' => ['Government ID', $application->government_id_path],
            'selfie_with_id' => ['Selfie holding ID', $application->selfie_with_id_path],
            'business_permit' => ['Business permit / DTI registration', $application->business_permit_path],
        ];

        if (isset($single[$type])) {
            [$label, $path] = $single[$type];
            if ($path) {
                return [$type, $path, $label];
            }
        }

        if ($type === 'additional_document') {
            foreach ($application->additional_document_paths ?? [] as $path) {
                if (BuildApplicationChecklistAction::additionalRef($path) === $ref) {
                    return [$ref, $path, 'Additional document'];
                }
            }
        }

        if ($type === 'portfolio_image') {
            $image = PhotographerPortfolioImage::query()
                ->where('user_id', $application->user_id)
                ->where('status', PortfolioImageStatus::Active)
                ->find($ref);
            if ($image) {
                return [(string) $image->id, $image->path, 'Portfolio image'];
            }
        }

        throw ValidationException::withMessages(['item_ref' => ['That item was not found on this application.']]);
    }
}