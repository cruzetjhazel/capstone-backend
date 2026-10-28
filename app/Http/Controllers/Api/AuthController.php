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
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Actions\Photographer\RegisterPhotographerAction;
use App\Http\Requests\RegisterPhotographerRequest;

class AuthController extends Controller
{
    use ApiResponses;

    public function register(RegisterClientRequest $request, RegisterClientAction $action)
    {
        $user = $action->execute($request->validated());
        $this->sendVerificationEmail($user);
        $token = $user->createToken('api')->plainTextToken;

        return $this->success(
            ['user' => new UserResource($user), 'token' => $token],
            'Account created successfully.',
            201
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

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        return redirect("{$frontend}/email-verified?status=success");
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, 'Your email is already verified.');
        }

        $user->sendEmailVerificationNotification();

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
    public function registerPhotographer(RegisterPhotographerRequest $request, RegisterPhotographerAction $action)
    {
        $user = $action->execute($request->validated());
        $this->sendVerificationEmail($user);
        $token = $user->createToken('api')->plainTextToken;

        return $this->success(
            [
                'user' => new UserResource($user),
                'application' => new \App\Http\Resources\PhotographerApplicationResource($user->photographerApplication),
                'token' => $token,
            ],
            'Photographer account created. Complete and submit your application to begin the review process.',
            201
        );
    }
    public function checkEmail(Request $request)
{
    $email = strtolower(trim((string) $request->input('email')));

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['email' => $email],
        ['email' => ['required', 'email:rfc,dns']]
    );

    if ($validator->fails()) {
        return response()->json(['available' => false, 'reason' => 'invalid_format']);
    }

    $exists = User::whereRaw('LOWER(email) = ?', [$email])->exists();

    return response()->json([
        'available' => !$exists,
        'reason' => $exists ? 'taken' : null,
    ]);
}
}