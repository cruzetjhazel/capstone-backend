<?php

use App\Models\BookingHour;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()->chunkById(200, function ($users) {
            foreach ($users as $user) {
                if (! $user->isPhotographer() || ! $user->isApprovedPhotographer()) {
                    continue;
                }

                // Never touch a photographer who already configured hours.
                if (BookingHour::where('user_id', $user->id)->exists()) {
                    continue;
                }

                foreach (range(0, 6) as $day) {
                    BookingHour::create([
                        'user_id' => $user->id,
                        'day_of_week' => $day,
                        'start_time' => '07:00',
                        'end_time' => '22:00',
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: these rows can't be told apart from hours a
        // photographer later edited, so they are not removed automatically.
    }
};