<?php

namespace App\Http\Controllers\Api\Client;

use App\Actions\Client\ChangeContactNumberAction;
use App\Actions\Client\RequestEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeContactNumberRequest;
use App\Http\Requests\ChangeEmailRequest;
use App\Models\User;
use App\Notifications\EmailChanged;
use App\Notifications\VerifyNewEmail;
use App\Support\Mask;
use App\Traits\ApiResponses;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ContactChangeController extends Controller
{
    use ApiResponses;

    /** POST /client/change-contact-number */
    public function changeContactNumber(ChangeContactNumberRequest $request, ChangeContactNumberAction $action)
    {
        $user = $request->user();
        abort_unless($user->isClient(), 403);

        $action->execute($user, $request->validated('current_password'), $request->validated('phone_number'));

        return $this->success(['phone_number' => $user->phone_number], 'Contact number updated.');
    }

    /** POST /client/change-email */
    public function requestEmailChange(ChangeEmailRequest $request, RequestEmailChangeAction $action)
    {
        $user = $request->user();
        abort_unless($user->isClient(), 403);

        $action->execute($user, $request->validated('current_password'), $request->validated('email'));

        return $this->success(
            ['pending_email' => $user->pending_email],
            'Verification link sent to the new address. Your current email stays active until you verify it.'
        );
    }

    /** POST /client/change-email/resend */
    public function resendEmailChange(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isClient(), 403);

        if (! $user->pending_email) {
            throw ValidationException::withMessages(['email' => ['There is no pending email change.']]);
        }

        try {
            Notification::route('mail', $user->pending_email)->notify(new VerifyNewEmail($user));
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'email' => ["We couldn't send the verification email. Please try again in a moment."],
            ]);
        }

        return $this->success(null, 'Verification link re-sent.');
    }

    /** POST /client/change-email/cancel */
    public function cancelEmailChange(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isClient(), 403);

        if ($user->pending_email) {
            $user->forceFill(['pending_email' => null, 'pending_email_requested_at' => null])->save();

            app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
                causer: $user,
                subject: $user,
                action: 'account.email_change_cancelled',
                description: 'Cancelled a pending email change',
            );
        }

        return $this->success(null, 'Email change cancelled.');
    }

    /**
     * GET /auth/email/change/verify/{id}/{hash}
     * Opened from the link sent to the NEW address (signed URL, no login needed).
     * Same contract as AuthController::verifyEmail: always ends in a redirect to the React app.
     */
    public function verifyEmailChange(Request $request, $id, $hash)
    {
        $frontend = rtrim(config('frontend.url'), '/');

        if (! $request->hasValidSignature()) {
            return redirect("{$frontend}/email-verified?status=expired");
        }

        $oldEmail = null;

        $swap = DB::transaction(function () use ($id, $hash, &$oldEmail) {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();
            $pending = $user ? strtolower((string) $user->pending_email) : '';

            if (! $user || $pending === '' || ! hash_equals(sha1($pending), (string) $hash)) {
                return null;
            }

            // The address may have been registered by someone else while this was pending.
            $inUse = User::query()
                ->where('id', '!=', $user->id)
                ->whereRaw('LOWER(email) = ?', [$pending])
                ->exists();

            if ($inUse) {
                $user->forceFill(['pending_email' => null, 'pending_email_requested_at' => null])->save();

                return null;
            }

            $oldEmail = $user->email;

            $user->forceFill([
                'email' => $pending,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_requested_at' => null,
            ])->save();

            return $user;
        });

        if (! $swap) {
            return redirect("{$frontend}/email-verified?status=invalid");
        }

        event(new Verified($swap));

        // Tell the PREVIOUS address, so a hijacked change is noticed. Best effort.
        if ($oldEmail) {
            try {
                Notification::route('mail', $oldEmail)->notify(new EmailChanged($swap->email));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
            causer: $swap,
            subject: $swap,
            action: 'account.email_changed',
            description: 'Verified and switched to a new email address ('.Mask::email($swap->email).')',
        );

        return redirect("{$frontend}/email-verified?status=success");
    }
}