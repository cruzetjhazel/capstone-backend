<?php

namespace App\Actions\Client;

use App\Models\User;
use App\Support\Mask;
use App\Support\PhilippinePhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Self-service contact number change. Password re-authentication is required.
 * The number is NOT verified (the app has no SMS/OTP), so no verified flag is set.
 */
class ChangeContactNumberAction
{
    public function execute(User $user, string $currentPassword, string $phone): User
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (PhilippinePhone::same($user->phone_number, $phone)) {
            throw ValidationException::withMessages([
                'phone_number' => ['That is already your contact number.'],
            ]);
        }

        $taken = fn (): ValidationException => ValidationException::withMessages([
            'phone_number' => ['That contact number is already registered to another account.'],
        ]);

        // Rows are in mixed formats (+639…, 09…), so check every variant of the same number.
        $inUse = User::query()
            ->where('id', '!=', $user->id)
            ->whereIn('phone_number', PhilippinePhone::variants($phone))
            ->exists();

        if ($inUse) {
            throw $taken();
        }

        try {
            $user->forceFill(['phone_number' => $phone])->save();
        } catch (UniqueConstraintViolationException) {
            throw $taken(); // lost a race with another request
        }

        app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
            causer: $user,
            subject: $user,
            action: 'account.contact_number_changed',
            description: 'Changed their contact number to '.Mask::phone($phone).' (not independently verified)',
        );

        return $user;
    }
}