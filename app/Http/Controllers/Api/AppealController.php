<?php

namespace App\Http\Controllers\Api;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AppealController extends Controller
{
    use ApiResponses;

    /**
     * POST /auth/appeal  (public, rate limited)
     * A suspended user can't log in, so identity is proven with email + password.
     */
    public function store(Request $request, LogActivityAction $activityLogger)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->account_status !== AccountStatus::Suspended) {
            throw ValidationException::withMessages([
                'email' => ['Only suspended accounts can submit an appeal.'],
            ]);
        }

        // One appeal per suspension: any appeal sent since the account was suspended counts.
        $existing = SuspensionAppeal::where('user_id', $user->id)
            ->when($user->suspended_at, fn ($q) => $q->where('created_at', '>=', $user->suspended_at))
            ->latest('id')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'message' => [$existing->status === 'pending'
                    ? 'You already have an appeal waiting for review.'
                    : 'You can only appeal once for each suspension.'],
            ]);
        }

        $attachments = array_map(function ($file) {
            $path = $file->store('appeals', 'public');

            return [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
            ];
        }, $request->file('evidence', []));

        $appeal = SuspensionAppeal::create([
            'user_id' => $user->id,
            'message' => $data['message'],
            'attachments' => $attachments,
            'status' => 'pending',
        ]);

        $activityLogger->execute(
            causer: $user,
            subject: $appeal,
            action: 'appeal.submitted',
            description: 'Submitted an appeal against their account suspension',
            metadata: ['attachments' => count($attachments)],
        );

        // Tell every administrator there is an appeal waiting.
        $appeal->load('user');
        User::where('account_type', \App\Enums\AccountType::Administrator)->get()
            ->each(fn (User $admin) => $admin->notify(new \App\Notifications\SuspensionAppealSubmittedNotification($appeal)));

        return $this->success(
            ['status' => 'pending', 'submitted_at' => $appeal->created_at->toIso8601String()],
            'Your appeal was submitted. An administrator will review it.',
            201
        );
    }
}