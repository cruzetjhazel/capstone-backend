<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function create(User $user): bool
    {
        return $user->isClient();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->id === $booking->client_id || $user->id === $booking->photographer_id;
    }

    public function requestCancellation(User $user, Booking $booking): bool
    {
        return $user->id === $booking->client_id;
    }

    public function confirmCompletion(User $user, Booking $booking): bool
    {
        return $user->id === $booking->client_id;
    }

    public function requestReschedule(User $user, Booking $booking): bool
    {
        return $user->id === $booking->client_id;
    }

    public function decideReschedule(User $user, Booking $booking): bool
    {
        return $user->id === $booking->photographer_id && $user->isEligibleForBusinessManagement();
    }

    public function modify(User $user, Booking $booking): bool
    {
        return $user->id === $booking->client_id;
    }

    public function respond(User $user, Booking $booking): bool
    {
        return $user->id === $booking->photographer_id && $user->isEligibleForBusinessManagement();
    }

    public function decideCancellation(User $user, Booking $booking): bool
    {
        return $user->id === $booking->photographer_id && $user->isEligibleForBusinessManagement();
    }

    public function accommodate(User $user, Booking $booking): bool
    {
        return $user->id === $booking->photographer_id && $user->isEligibleForBusinessManagement();
    }

    public function manageServiceTracker(User $user, Booking $booking): bool
    {
        return $user->id === $booking->photographer_id && $user->isEligibleForBusinessManagement();
    }

    public function requestExtension(User $user, Booking $booking): bool
    {
        return $user->id === $booking->client_id;
    }

    public function decideExtension(User $user, Booking $booking): bool
    {
        return $user->id === $booking->photographer_id && $user->isEligibleForBusinessManagement();
    }
}