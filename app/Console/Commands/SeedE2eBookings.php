<?php

namespace App\Console\Commands;

use App\Enums\BookingNonCompletionReason;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Console\Command;

class SeedE2eBookings extends Command
{
    protected $signature = 'e2e:seed-bookings
        {--client=client@example.test : Email of the E2E client}
        {--photographer=aurora-studios@example.test : Email of the E2E photographer}
        {--env= : Path to the frontend .env.e2e file to update}';

    protected $description = 'Create the bookings used by the Playwright payment, booking, no-show and admin-review tests, and optionally write their IDs to .env.e2e';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');
            return self::FAILURE;
        }

        $client = User::where('email', $this->option('client'))->first();
        $photographer = User::where('email', $this->option('photographer'))->first();

        if (! $client || ! $photographer) {
            $this->error('Client or photographer email not found.');
            return self::FAILURE;
        }

        $base = [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'total_price' => 10000,
            'hold_expires_at' => now()->addDays(30),
        ];

        $pending = Booking::factory()->create($base + [
            'status' => BookingStatus::Pending,
            'event_date' => now()->addDays(60)->toDateString(),
        ]);
        $a = Booking::factory()->accepted()->create($base + [
            'event_date' => now()->addDays(61)->toDateString(),
        ]);
        $b = Booking::factory()->accepted()->create($base + [
            'event_date' => now()->addDays(62)->toDateString(),
        ]);

        // Paid, confirmed bookings that started yesterday: one for each no-show test (each is used up by its test).
        $started = [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'event_date' => now()->subDay()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '11:30',
        ];
        $noShowByClient = Booking::factory()->confirmed()->create($started);
        $noShowByPhotographer = Booking::factory()->confirmed()->create($started);

        // Confirmed booking that has not started yet (read-only checks).
        $notStarted = Booking::factory()->confirmed()->create(array_merge($started, [
            'event_date' => now()->addDays(10)->format('Y-m-d'),
        ]));

        // Confirmed bookings with an open photographer no-show report, waiting for admin review (admin tests).
        $reported = $started + [
            'non_completion_reason' => BookingNonCompletionReason::PhotographerNoShow,
            'non_completion_reported_by' => $client->id,
            'non_completion_reported_at' => now()->subHour(),
            'non_completion_dispute_deadline_at' => now()->addHours(15),
            'non_completion_review_status' => 'pending_admin',
        ];
        $adminUphold = Booking::factory()->confirmed()->create($reported);
        $adminDispute = Booking::factory()->confirmed()->create($reported);

        // Pending requests used up by the accept and lifecycle tests. Each gets its own date so the
        // accepted booking never overlaps another one (an overlap would put it on the waitlist).
        $accept = Booking::factory()->create($base + [
            'status' => BookingStatus::Pending,
            'event_date' => now()->addDays(64)->toDateString(),
        ]);
$lifecycleDate = now()->addDays(75);

while (
    Booking::where('photographer_id', $photographer->id)
        ->whereDate('event_date', $lifecycleDate->toDateString())
        ->whereIn('status', [
            BookingStatus::Confirmed->value,
            BookingStatus::Pending->value,
            BookingStatus::Waitlisted->value,
        ])
        ->exists()
) {
            $lifecycleDate->addDay();
        }

// Find a date with no existing bookings for this photographer.
$lifecycleDate = now()->addDays(75);

while (
    Booking::where('photographer_id', $photographer->id)
        ->whereDate('event_date', $lifecycleDate->toDateString())
        ->whereIn('status', [
            BookingStatus::Confirmed->value,
            BookingStatus::Pending->value,
            BookingStatus::Waitlisted->value,
        ])
        ->exists()
) {
    $lifecycleDate->addDay();
}

    $lifecycle = Booking::factory()->create(array_merge($base, [
        'status' => BookingStatus::Pending,
        'event_date' => $lifecycleDate->toDateString(),
        'start_time' => '13:00',
        'end_time' => '15:30',
    ]));

        // Paid, confirmed booking whose shoot ended 4 days ago, still at the "Event Day" stage.
        $complete = Booking::factory()->confirmed()->create(array_merge($started, [
            'event_date' => now()->subDays(4)->format('Y-m-d'),
            'service_status' => 'event_day',
            'service_status_updated_at' => now()->subDays(4),
        ]));

        // Pending request that starts in 2 hours: too soon to collect a payment.
        $soon = now()->addHours(2);
        $end = $soon->copy()->addMinutes(30);

        // Keep the booking within one calendar day because the database
        // requires the end time to be later than the start time.
        if ($end->toDateString() !== $soon->toDateString()) {
            $end = $soon->copy()->endOfDay()->startOfMinute();
        }

        $tooSoon = Booking::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'event_date' => $soon->format('Y-m-d'),
            'start_time' => $soon->format('H:i'),
            'end_time' => $end->format('H:i'),
            'hold_expires_at' => $soon,
        ]);

        $lines = [
            'E2E_BOOKING_PENDING' => $pending->id,
            'E2E_BOOKING_A' => $a->id,
            'E2E_BOOKING_B' => $b->id,
            'E2E_BOOKING_NOSHOW_BY_CLIENT' => $noShowByClient->id,
            'E2E_BOOKING_NOSHOW_BY_PHOTOGRAPHER' => $noShowByPhotographer->id,
            'E2E_BOOKING_NOT_STARTED' => $notStarted->id,
            'E2E_BOOKING_TOO_SOON' => $tooSoon->id,
            'E2E_BOOKING_ADMIN_UPHOLD' => $adminUphold->id,
            'E2E_BOOKING_ADMIN_DISPUTE' => $adminDispute->id,
            'E2E_BOOKING_ACCEPT' => $accept->id,
            'E2E_BOOKING_LIFECYCLE' => $lifecycle->id,
            'E2E_BOOKING_COMPLETE' => $complete->id,
        ];

        foreach ($lines as $key => $id) {
            $this->line("{$key}={$id}");
        }

        if ($path = $this->option('env')) {
            if (! is_file($path)) {
                $this->error("File not found: {$path}");
                return self::FAILURE;
            }

            $content = file_get_contents($path);
            foreach ($lines as $key => $id) {
                $pattern = '/^'.$key.'=[^\r\n]*/m';
                $content = preg_match($pattern, $content)
                    ? preg_replace($pattern, "{$key}={$id}", $content)
                    : rtrim($content)."\n{$key}={$id}\n";
            }
            file_put_contents($path, $content);
            $this->info("Updated {$path}");
        }

        return self::SUCCESS;
    }
}