<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserManagementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->whenLoaded('photographerApplication', fn () => $this->photographerApplication);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'account_type' => $this->account_type?->value ?? $this->account_type,
            'photographer_type' => $application?->photographer_type?->value ?? $application?->photographer_type,
            'is_freelancer' => (bool) ($application?->is_freelancer),
            'is_studio_owner' => (bool) ($application?->is_studio_owner),
            'account_status' => $this->account_status,
            'application_status' => $application?->status?->value ?? $application?->status,
            'joined_at' => $this->created_at?->toISOString(),
        ];
    }
}