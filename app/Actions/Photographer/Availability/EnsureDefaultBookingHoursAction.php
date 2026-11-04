<?php

namespace App\Actions\Photographer\Availability;

use App\Models\BookingHour;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnsureDefaultBookingHoursAction
{
    public const DEFAULT_START = '07:00';
    public const DEFAULT_END = '22:00';

    public function execute(User $photographer): void
    {
        if ($photographer->booking_hours_initialized) {
            return;
        }

        DB::transaction(function () use ($photographer) {
            // Lock the row so two simultaneous requests can't seed twice.
            $fresh = User::whereKey($photographer->id)->lockForUpdate()->first();

            if ($fresh->booking_hours_initialized) {
                $photographer->booking_hours_initialized = true;
                return;
            }

            if (! BookingHour::where('user_id', $fresh->id)->exists()) {
                foreach (range(0, 6) as $day) {
                    BookingHour::create([
                        'user_id' => $fresh->id,
                        'day_of_week' => $day,
                        'start_time' => self::DEFAULT_START,
                        'end_time' => self::DEFAULT_END,
                    ]);
                }
            }

            $fresh->forceFill(['booking_hours_initialized' => true])->save();
            $photographer->booking_hours_initialized = true;
        });
    }
}