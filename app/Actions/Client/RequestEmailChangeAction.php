<?php

namespace App\Actions\Client;

use App\Models\User;
use App\Notifications\EmailChangeRequested;
use App\Notifications\VerifyNewEmail;
use App\Support\Mask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Starts a self-service email change. `users.email` and `email_verified_at` are NOT
 * touched here; the new address waits in `pending_email` until its signed link is opened
 * (see ContactChangeController::verifyEmailChange).
 */
class RequestEmailChangeAction
{
    public function execute(User $user, string $currentPassword, string $newEmail): User
    {
        $newEmail = strtolower(trim($newEmail));

        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (strtolower((string) $user->email) === $newEmail) {
            throw ValidationException::withMessages([
                'email' => ['That is already your email address.'],
            ]);
        }

        $taken = fn (): ValidationException => ValidationException::withMessages([
            'email' => ['That email address is already registered to another account.'],
        ]);

        // Case-insensitive on every database, not just MySQL's default collation.
        $inUse = User::query()
            ->where('id', '!=', $user->id)
            ->whereRaw('LOWER(email) = ?', [$newEmail])
            ->exists();

        if ($inUse) {
            throw $taken();
        }

        $previousPending = $user->pending_email;
        $previousRequestedAt = $user->pending_email_requested_at;

        try {
            $user->forceFill([
                'pending_email' => $newEmail,
                'pending_email_requested_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw $taken(); // another account already has this address pending, or won the race
        }

        try {
            Notification::route('mail', $newEmail)->notify(new VerifyNewEmail($user));
        } catch (\Throwable $e) {
            report($e);

            // Don't leave a pending address nobody can verify.
            $user->forceFill([
                'pending_email' => $previousPending,
                'pending_email_requested_at' => $previousRequestedAt,
            ])->save();

            throw ValidationException::withMessages([
                'email' => ["We couldn't send the verification email. Please try again in a moment."],
            ]);
        }

        // Heads-up to the CURRENT address. Best effort: never fail the request over it.
        try {
            Notification::route('mail', $user->email)->notify(new EmailChangeRequested($newEmail));
        } catch (\Throwable $e) {
            report($e);
        }

        app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
            causer: $user,
            subject: $user,
            action: 'account.email_change_requested',
            description: 'Requested an email change to '.Mask::email($newEmail),
        );

        return $user;
    }
}