<?php

namespace App\Actions\Client;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeactivateAccountAction
{
    public function __construct(protected LogActivityAction $activityLogger)
    {
    }

    public function execute(User $user, string $password): User
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        if ($user->hasOngoingBookings()) {
            throw ValidationException::withMessages([
                'account' => ['Your account cannot be deactivated while you have ongoing bookings.'],
            ]);
        }

        if ($user->reactivated_at && \Illuminate\Support\Carbon::parse($user->reactivated_at)->addDays(7)->isFuture()) {
            $until = \Illuminate\Support\Carbon::parse($user->reactivated_at)->addDays(7)->format('F j, Y');

            throw ValidationException::withMessages([
                'account' => ["You recently reactivated your account. You can deactivate it again on {$until}."],
            ]);
        }

        $user->account_status = AccountStatus::Deactivated;
        $user->deactivated_at = now();
        $user->save();
        $user->tokens()->delete();
        $user->tokens()->delete();

        $fresh = $user->fresh();

        $this->activityLogger->execute(
            causer: $fresh,
            subject: $fresh,
            action: 'account.deactivated',
            description: 'Deactivated their own account',
        );

        return $fresh;
    }
}