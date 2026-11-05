<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\RegisterClientAction;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterClientRequest;
use App\Http\Resources\UserResource;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Rules\UniquePhoneNumber;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Notifications\RegistrationAttemptNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use App\Actions\Photographer\RegisterPhotographerAction;
use App\Http\Requests\RegisterPhotographerRequest;

class AuthController extends Controller
{
    use ApiResponses;

    public function register(RegisterClientRequest $request, RegisterClientAction $action)
    {
        return $this->registerAndSignIn(
            $request->validated(),
            fn (array $data) => $action->execute($data)
        );
    }

    public function login(LoginRequest $request)
    {
        $throttleKey = 'login-attempts:' . strtolower(trim((string) $request->validated('email')));

        // Limit and lockout time come from Admin > Settings (default: 5 attempts, 60 seconds).
        $maxAttempts = PlatformSetting::get('login_max_attempts');
        $lockoutSeconds = PlatformSetting::get('login_lockout_seconds');
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);
            $message = "Too many login attempts. Please try again in {$retryAfter} seconds.";

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['email' => [$message]],
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        $user = User::where('email', $request->validated('email'))->first();

        if (! $user) {
            Hash::make(Str::random(16)); // keeps response time the same for unknown emails
        }

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($throttleKey, $lockoutSeconds);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Correct password: reset the counter.
        RateLimiter::clear($throttleKey);

        if ($user->account_status !== AccountStatus::Active) {
            $iso = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->toIso8601String() : null;
            $appeal = $user->account_status === AccountStatus::Suspended
                ? \App\Models\SuspensionAppeal::where('user_id', $user->id)
                    ->when($user->suspended_at, fn ($q) => $q->where('created_at', '>=', $user->suspended_at))
                    ->latest('id')->first()
                : null;

            return response()->json([
                'success' => false,
                'message' => 'This account is not active.',
                'errors' => ['email' => ['This account is not active.']],
                'account' => [
                    'status' => $user->account_status->value,
                    'reason' => $user->account_status === AccountStatus::Suspended ? $user->suspension_reason : null,
                    'suspended_at' => $iso($user->suspended_at),
                    'deactivated_at' => $iso($user->deactivated_at),
                    'appeal' => $appeal ? [
                        'status' => $appeal->status,
                        'submitted_at' => $appeal->created_at?->toIso8601String(),
                        'admin_response' => $appeal->admin_response,
                    ] : null,
                ],
            ], 422);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->success(
            ['user' => new UserResource($user), 'token' => $token],
            'Logged in successfully.'
        );
    }

        /**
     * A user reactivating an account they deactivated themselves.
     * Requires the correct password. Suspended accounts can never use this.
     */
    public function reactivate(LoginRequest $request)
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->account_status !== AccountStatus::Deactivated) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        $user->account_status = AccountStatus::Active;
        $user->reactivated_at = now();
        $user->save();

        app(\App\Actions\ActivityLog\LogActivityAction::class)->execute(
            causer: $user,
            subject: $user,
            action: 'account.reactivated',
            description: 'Reactivated their own account',
        );

        return $this->success(null, 'Account reactivated. You can now log in.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    public function me(Request $request)
    {
        return $this->success(new UserResource($request->user()));
    }

    /**
     * Opened from the link in the verification email (signed URL, no login needed).
     * Always ends in a redirect to the React app.
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $frontend = rtrim(config('frontend.url'), '/');

        if (! $request->hasValidSignature()) {
            return redirect("{$frontend}/email-verified?status=expired");
        }

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return redirect("{$frontend}/email-verified?status=invalid");
        }

        // Already verified (for example an older link): nothing to do.
        if ($user->hasVerifiedEmail()) {
            return redirect("{$frontend}/email-verified?status=success");
        }

        // The link carries a one-time value that is replaced whenever a new email is
        // sent, so only the newest link works, and it works once.
        $claimed = User::whereKey($user->id)
            ->whereNull('email_verified_at')
            ->where('email_verification_nonce', hash('sha256', (string) $request->query('n', '')))
            ->update(['email_verified_at' => now(), 'email_verification_nonce' => null]);

        if (! $claimed) {
            return redirect("{$frontend}/email-verified?status=invalid");
        }

        event(new \Illuminate\Auth\Events\Verified($user->fresh()));

        return redirect("{$frontend}/email-verified?status=success");
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, 'Your email is already verified.');
        }

        // 1 email per minute and 5 per hour for each account.
        $limits = ["verify-resend-min:{$user->id}" => 1, "verify-resend-hour:{$user->id}" => 5];
        $retryAfter = 0;
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
        }

        if ($retryAfter > 0) {
            $wait = $retryAfter > 120 ? ceil($retryAfter / 60).' minutes' : "{$retryAfter} seconds";
            $message = "Please wait {$wait} before requesting another email.";

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['email' => [$message]],
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        RateLimiter::hit("verify-resend-min:{$user->id}", 60);
        RateLimiter::hit("verify-resend-hour:{$user->id}", 3600);

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);

            return $this->error("We couldn't send the email. Please try again in a moment.", 503);
        }

        return $this->success(null, 'Verification email sent.');
    }

    private function sendVerificationEmail(User $user): void
    {
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e); // signup must not fail just because the mail failed
        }
    }

    private const GENERIC_REGISTRATION_MESSAGE =
        'Thank you for registering! If your registration can proceed, check your email for the next steps.';

    /**
     * Same 202 response whether the account was created, the email already exists, or
     * the phone number is taken, so the form can't be used to find out who is
     * registered. No token is issued: the user must verify and sign in.
     */
    private function registerWithGenericResponse(array $data, callable $create)
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = $data['phone_number'] ?? null;

        $emailTaken = User::whereRaw('LOWER(email) = ?', [$email])->exists();
        $phoneTaken = ! $emailTaken && filled($phone) && UniquePhoneNumber::exists((string) $phone);

        if ($emailTaken || $phoneTaken) {
            Hash::make(Str::random(16)); // same cost as creating an account

            if ($emailTaken) {
                $this->scheduleDuplicateNotice($email);
            }
            // A taken phone number gets no email: it would reveal that the number is registered.
        } else {
            try {
                $user = $create($data);
                // Sent after the response so timing doesn't differ between the cases.
                app()->terminating(fn () => $this->sendVerificationEmail($user));
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race with another request for the same email or phone: treat as a duplicate.
            }
        }

        return $this->success(null, self::GENERIC_REGISTRATION_MESSAGE, 202);
    }

    private function scheduleDuplicateNotice(string $email): void
    {
        $key = sha1($email);

        // At most 1 notice per hour and 3 per day for any one address.
        if (RateLimiter::tooManyAttempts("dup-reg-hour:{$key}", 1) || RateLimiter::tooManyAttempts("dup-reg-day:{$key}", 3)) {
            return;
        }
        RateLimiter::hit("dup-reg-hour:{$key}", 3600);
        RateLimiter::hit("dup-reg-day:{$key}", 86400);

        $owner = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $owner) {
            return;
        }

        app()->terminating(function () use ($owner) {
            try {
                $owner->notify(new RegistrationAttemptNotification());
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
        public function registerPhotographer(RegisterPhotographerRequest $request, RegisterPhotographerAction $action)
    {
        return $this->registerAndSignIn(
            $request->validated(),
            fn (array $data) => $action->execute($data)
        );
    }

    private function registerAndSignIn(array $data, callable $create)
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = $data['phone_number'] ?? null;

        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['This email is already registered. Try signing in instead.']]);
        }
        if (filled($phone) && UniquePhoneNumber::exists((string) $phone)) {
            throw ValidationException::withMessages(['phone_number' => ['This phone number is already registered.']]);
        }

        try {
            $user = $create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => ['This email or phone number is already registered.']]);
        }

        app()->terminating(fn () => $this->sendVerificationEmail($user));

        return $this->success(
            ['user' => new UserResource($user), 'token' => $user->createToken('api')->plainTextToken],
            'Account created. Check your email to verify it.',
            201
        );
    }
}