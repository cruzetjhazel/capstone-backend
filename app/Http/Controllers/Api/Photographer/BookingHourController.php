<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Photographer\Availability\CreateBookingHourAction;
use App\Actions\Photographer\Availability\DeleteBookingHourAction;
use App\Actions\Photographer\Availability\EnsureDefaultBookingHoursAction;
use App\Actions\Photographer\Availability\UpdateBookingHourAction;
use App\Actions\Photographer\Availability\UpdateSlotIntervalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingHourRequest;
use App\Http\Resources\BookingHourResource;
use App\Models\BookingHour;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class BookingHourController extends Controller
{
    use ApiResponses;

    public function index(Request $request, EnsureDefaultBookingHoursAction $ensureDefaults)
    {
        $this->authorize('viewAny', BookingHour::class);

        $ensureDefaults->execute($request->user());

        return $this->success(
            BookingHourResource::collection(
                $request->user()->bookingHours()->orderBy('day_of_week')->orderBy('start_time')->get()
            )
        );
    }

    public function store(BookingHourRequest $request, CreateBookingHourAction $action, EnsureDefaultBookingHoursAction $ensureDefaults)
    {
        $this->authorize('create', BookingHour::class);

        $ensureDefaults->execute($request->user());

        $hour = $action->execute($request->user(), $request->validated());

        return $this->success(new BookingHourResource($hour), 'Booking hours added.', 201);
    }

    public function update(BookingHourRequest $request, BookingHour $bookingHour, UpdateBookingHourAction $action)
    {
        $this->authorize('update', $bookingHour);

        $hour = $action->execute($bookingHour, $request->validated());

        return $this->success(new BookingHourResource($hour), 'Booking hours updated.');
    }

    public function destroy(BookingHour $bookingHour, DeleteBookingHourAction $action)
    {
        $this->authorize('delete', $bookingHour);

        $action->execute($bookingHour);

        return $this->success(null, 'Booking hours removed.');
    }

    public function interval(Request $request)
    {
        $this->authorize('viewAny', BookingHour::class);

        return $this->success(['slot_interval_minutes' => $request->user()->slot_interval_minutes ?? 30]);
    }

    public function updateInterval(Request $request, UpdateSlotIntervalAction $action)
    {
        $this->authorize('update', BookingHour::class);

        $request->validate(['slot_interval_minutes' => ['required', 'integer', 'in:30,45,60']]);

        $user = $action->execute($request->user(), $request->integer('slot_interval_minutes'));

        return $this->success(['slot_interval_minutes' => $user->slot_interval_minutes], 'Booking interval updated.');
    }
}