<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWalkInClientRequest;
use App\Http\Requests\UpdateWalkInClientRequest;
use App\Http\Resources\WalkInClientResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\WalkInClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Merged Client list.
     *
     * Rule 46 — Registered Clients automatically appear here when they have
     * a booking relationship with this Photographer (derived from Booking,
     * no stored record).
     * Rules 47-48 — Walk-in Clients are separate manually-entered records
     * that never get a platform account.
     */
    public function index(Request $request): JsonResponse
    {
        $photographerId = $request->user()->id;

        $registered = Booking::query()
            ->where('photographer_id', $photographerId)
            ->whereNotNull('client_id') // manual bookings belong to walk-in clients, listed below
            ->with('client.clientProfile')
            ->get()
            ->groupBy('client_id')
            ->map(function ($clientBookings) use ($photographerId) {
                $client = $clientBookings->first()->client;

                $hasActiveBooking = $clientBookings->contains(
                    fn ($booking) => in_array($booking->status, [
                        BookingStatus::Pending,
                        BookingStatus::Confirmed,
                    ], true)
                );

                $spent = (float) Payment::query()
                    ->where('client_id', $client->id)
                    ->where('photographer_id', $photographerId)
                    ->sum('amount');

                return [
                    'id' => 'R-' . $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'phone' => $client->phone_number,
                    'location' => $client->clientProfile?->address,
                    'bookings' => $clientBookings->count(),
                    'spent' => $spent,
                    'type' => 'registered',
                    'status' => $hasActiveBooking ? 'active' : 'inactive',
                    'source' => 'Platform',
                    'joined_year' => (int) $client->created_at->format('Y'),
                    // Registered clients are derived from bookings, not a
                    // stored record — they're never archivable.
                    'archived_at' => null,
                ];
            })
            ->values();

        $walkIns = WalkInClient::query()
            ->where('photographer_id', $photographerId)
            ->where('status', '!=', 'archived')
            ->latest()
            ->get();

        $walkInsFormatted = collect(WalkInClientResource::collection($walkIns)->resolve());

        return response()->json([
            'data' => $registered->concat($walkInsFormatted)->values(),
        ]);
    }

    public function store(StoreWalkInClientRequest $request): WalkInClientResource
    {
        $client = WalkInClient::create([
            ...$request->validated(),
            'photographer_id' => $request->user()->id,
            'status' => 'inactive',
        ]);

        return new WalkInClientResource($client);
    }

    public function update(UpdateWalkInClientRequest $request, WalkInClient $walkInClient): WalkInClientResource
    {
        abort_unless($walkInClient->photographer_id === $request->user()->id, 403);

        $walkInClient->update($request->validated());

        return new WalkInClientResource($walkInClient);
    }

    public function archive(Request $request, WalkInClient $walkInClient): WalkInClientResource
    {
        abort_unless($walkInClient->photographer_id === $request->user()->id, 403);

        $walkInClient->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return new WalkInClientResource($walkInClient);
    }

    public function restore(Request $request, WalkInClient $walkInClient): WalkInClientResource
    {
        abort_unless($walkInClient->photographer_id === $request->user()->id, 403);

        // Mirrors the default status new walk-ins are created with in store().
        $walkInClient->update([
            'status' => 'inactive',
            'archived_at' => null,
        ]);

        return new WalkInClientResource($walkInClient);
    }

    public function destroy(Request $request, WalkInClient $walkInClient): JsonResponse
    {
        abort_unless($walkInClient->photographer_id === $request->user()->id, 403);
        abort_unless($walkInClient->status === 'archived', 422, 'Only archived clients can be permanently deleted.');
        abort_if($walkInClient->bookings()->exists(), 422, 'This client has recorded bookings, so they cannot be permanently deleted. Keep them archived instead.');

        $walkInClient->delete();

        return response()->json(['message' => 'Walk-in client permanently deleted.']);
    }
}