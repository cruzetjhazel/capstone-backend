<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ClientProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;

        // Same 7-day rule as DeactivateAccountAction. Null means no waiting period.
        $deactivateAvailableOn = $user->reactivated_at
            ? \Illuminate\Support\Carbon::parse($user->reactivated_at)->addDays(7)
            : null;
        if ($deactivateAvailableOn && ! $deactivateAvailableOn->isFuture()) {
            $deactivateAvailableOn = null;
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            // Set only while an email change awaits verification; `email` stays the active address.
            'pending_email' => $user->pending_email,
            'pending_email_requested_at' => $user->pending_email_requested_at?->toISOString(),
            'phone_number' => $user->phone_number,
            'birthday' => $this->birthday?->format('Y-m-d'),
            'gender' => $this->gender,
            'address' => $this->address,
            'profile_photo_url' => $this->profile_photo_path ? Storage::disk('public')->url($this->profile_photo_path) : null,
            'deactivate_available_on' => $deactivateAvailableOn?->toISOString(),
            // Account Overview card (User casts account_status to the AccountStatus enum).
            'account_status' => $user->account_status?->value,
            'member_since' => $user->created_at?->toISOString(),
        ];
    }
}