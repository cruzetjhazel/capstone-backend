<?php

namespace App\Policies;

use App\Models\PhotographerApplication;
use App\Models\User;

class PhotographerApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, PhotographerApplication $application): bool
    {
        return $user->isAdministrator() || $user->id === $application->user_id;
    }

    public function update(User $user, PhotographerApplication $application): bool
    {
        return $user->id === $application->user_id;
    }

    public function submit(User $user, PhotographerApplication $application): bool
    {
        return $user->id === $application->user_id;
    }

    public function reapply(User $user, PhotographerApplication $application): bool
    {
        return $user->id === $application->user_id;
    }

    public function approve(User $user, PhotographerApplication $application): bool
    {
        return $user->isAdministrator();
    }

    public function reject(User $user, PhotographerApplication $application): bool
    {
        return $user->isAdministrator();
    }

    public function requestRevision(User $user, PhotographerApplication $application): bool
    {
        return $user->isAdministrator();
    }

    public function review(User $user, PhotographerApplication $application): bool
    {
        return $user->isAdministrator();
    }
}