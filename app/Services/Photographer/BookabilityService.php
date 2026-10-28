<?php

namespace App\Services\Photographer;

use App\Enums\AccountStatus;
use App\Models\User;

class BookabilityService
{
    public function __construct(protected ProfileCompletenessService $completenessService)
    {
    }

    public function isBookable(User $photographer): bool
    {
        if (! $photographer->isPhotographer() || ! $photographer->isApprovedPhotographer()) {
            return false;
        }

        if ($photographer->account_status !== AccountStatus::Active) {
            return false;
        }

        // Unverified emails can register, log in and stay active, but are not bookable.
        if (! $photographer->hasVerifiedEmail()) {
            return false;
        }

        $checklist = $this->completenessService->evaluate($photographer);

        return $checklist['fully_bookable'];
    }
}