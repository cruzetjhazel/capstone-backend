<?php

namespace App\Actions\Photographer;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\PhotographerApplicationStatus;
use App\Models\BookingHour;
use App\Models\PhotographerApplication;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApprovePhotographerApplicationAction
{
    public function __construct(
        protected LogActivityAction $activityLogger,
        protected BuildApplicationChecklistAction $checklist,
    ) {
    }

    public function execute(PhotographerApplication $application, User $reviewer): PhotographerApplication
    {
        if ($application->status !== PhotographerApplicationStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => ['Only applications with status Pending Review can be approved.'],
            ]);
        }

        $blockers = $this->checklist->execute($application)['summary']['blockers'];

        if ($blockers !== []) {
            throw ValidationException::withMessages(['application' => $blockers]);
        }

        $application->fill([
            'status' => PhotographerApplicationStatus::Approved,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
            'revision_notes' => null,
        ])->save();

        $fresh = $application->fresh();

        $this->seedDefaultBookingHours($fresh->user_id);

        $this->activityLogger->execute(
            causer: $reviewer,
            subject: $fresh,
            action: 'application.approved',
            description: "Approved photographer application #{$fresh->id}",
        );

        return $fresh;
    }

    /**
     * New photographers start open 7:00 AM - 10:00 PM every day. They can edit
     * or remove any period later. Skipped if they already set their own hours
     * (e.g. an application that is approved again after a revision).
     */
    private function seedDefaultBookingHours(int $userId): void
    {
        if (BookingHour::where('user_id', $userId)->exists()) {
            return;
        }

        foreach (range(0, 6) as $day) {
            BookingHour::create([
                'user_id' => $userId,
                'day_of_week' => $day,
                'start_time' => '07:00',
                'end_time' => '22:00',
            ]);
        }
    }
}