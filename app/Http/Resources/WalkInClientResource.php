<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalkInClientResource extends JsonResource
{
    /** Manual bookings of this walk-in client, queried once per resource. */
    private ?array $manualStats = null;

    private function manualStats(): array
    {
        return $this->manualStats ??= $this->computeManualStats();
    }

    private function computeManualStats(): array
    {
        $bookings = \App\Models\Booking::query()
            ->where('walk_in_client_id', $this->id)
            ->where('status', '!=', \App\Enums\BookingStatus::Cancelled->value)
            ->get(['status', 'manual_amount_paid']);

        return [
            'count' => $bookings->count(),
            'spent' => (float) $bookings->sum('manual_amount_paid'),
            'active' => $bookings->contains(fn ($b) => $b->status === \App\Enums\BookingStatus::Confirmed),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => 'W-' . $this->id, // prefixed so it can't collide with registered-client user IDs in a merged list
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'location' => $this->location,
            // Only bookings the photographer recorded manually count here; walk-ins never book through the platform.
            'bookings' => $this->manualStats()['count'],
            'spent' => $this->manualStats()['spent'],
            'type' => 'walk-in',
            'status' => $this->status === 'archived' ? 'archived' : ($this->manualStats()['active'] ? 'active' : $this->status),
            'source' => $this->source,
            'joined_year' => (int) $this->created_at->format('Y'),
        ];
    }
}