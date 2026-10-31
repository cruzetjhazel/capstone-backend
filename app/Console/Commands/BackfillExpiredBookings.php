<?php

namespace App\Console\Commands;

use App\Actions\ActivityLog\LogActivityAction;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * One-off backfill for bookings that timed out (48h no photographer
 * decision, or unpaid acceptance) BEFORE ExpireStaleBookingHoldsAction
 * existed. Those got stamped status=Cancelled by whatever legacy logic
 * handled timeouts back then. This command finds them and flips them to
 * Expired, matching what ExpireStaleBookingHoldsAction would have done.
 *
 * Heuristic: a REAL cancellation only ever happens through the client
 * cancellation-request flow, which is decided by the studio
 * (ApproveCancellation / RejectCancellation) — that flow stamps
 * `cancellation_decision` and `cancellation_decided_at`. A timeout never
 * goes through that flow, so:
 *
 *   - status = cancelled AND cancellation_decision IS NULL
 *     AND cancellation_decided_at IS NULL
 *     -> nobody ever decided this cancellation -> it was a timeout
 *        under the old logic -> candidate to flip to Expired.
 *
 *   - status = cancelled AND cancellation_decision IS NOT NULL
 *     -> a studio explicitly approved a client's cancellation request
 *        -> a real cancellation -> leave alone.
 *
 * Usage:
 *   php artisan bookings:backfill-expired               # dry run (default)
 *   php artisan bookings:backfill-expired --apply        # actually writes
 *   php artisan bookings:backfill-expired --ids=12,45    # limit to specific bookings
 *   php artisan bookings:backfill-expired --apply --ids=12,45
 */
class BackfillExpiredBookings extends Command
{
    protected $signature = 'bookings:backfill-expired
        {--apply : Actually write the changes. Without this flag, the command only prints what it would do.}
        {--ids= : Comma-separated booking IDs to limit this run to (recommended for a first pass).}';

    protected $description = 'Flip old cancelled bookings that were actually unattended timeouts to the Expired status.';

    public function __construct(protected LogActivityAction $activityLogger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $idsOption = $this->option('ids');
        $onlyIds = $idsOption ? array_filter(array_map('trim', explode(',', $idsOption))) : null;

        $query = Booking::with('client')->where('status', BookingStatus::Cancelled);

        if ($onlyIds) {
            $query->whereIn('id', $onlyIds);
        }

        $bookings = $query->get();

        if ($bookings->isEmpty()) {
            $this->info('No cancelled bookings found to inspect.');
            return self::SUCCESS;
        }

        $candidates = $bookings->filter(
            fn ($b) => is_null($b->cancellation_decision) && is_null($b->cancellation_decided_at)
        );
        $realCancellations = $bookings->filter(
            fn ($b) => !is_null($b->cancellation_decision) || !is_null($b->cancellation_decided_at)
        );

        $this->line('');
        $this->info("Inspected {$bookings->count()} cancelled booking(s).");
        $this->line(" - No cancellation decision on record (likely stale timeouts, will flip): {$candidates->count()}");
        $this->line(" - Has a recorded cancellation decision (real cancellation, left alone): {$realCancellations->count()}");
        $this->line('');

        if ($candidates->isNotEmpty()) {
            $this->comment('Candidates to flip to Expired:');
            $this->table(
                ['ID', 'Client', 'Event Date', 'Cancellation Reason', 'hold_expires_at'],
                $candidates->map(fn ($b) => [
                    $b->id,
                    $b->client->name ?? '—',
                    $b->event_date,
                    $b->cancellation_reason ?? '—',
                    $b->hold_expires_at ?? '—',
                ])
            );
        }

        if ($realCancellations->isNotEmpty()) {
            $this->line('Real cancellations found in this batch (left alone):');
            $this->table(
                ['ID', 'Client', 'Event Date', 'Decision', 'Decided At'],
                $realCancellations->map(fn ($b) => [
                    $b->id,
                    $b->client->name ?? '—',
                    $b->event_date,
                    $b->cancellation_decision ?? '—',
                    $b->cancellation_decided_at ?? '—',
                ])
            );
        }

        if (!$apply) {
            $this->line('');
            $this->info('Dry run only — no changes written. Re-run with --apply to update the ' . $candidates->count() . ' candidate(s) above.');
            return self::SUCCESS;
        }

        if ($candidates->isEmpty()) {
            $this->info('Nothing to apply.');
            return self::SUCCESS;
        }

        if (!$this->confirm("About to flip {$candidates->count()} booking(s) from Cancelled to Expired. Continue?")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        foreach ($candidates as $booking) {
            $booking->update([
                'status' => BookingStatus::Expired,
            ]);

            $this->activityLogger->execute(
                causer: null,
                subject: $booking,
                action: 'booking.expired.backfilled',
                description: "Booking #{$booking->id} reclassified from Cancelled to Expired — pre-dates ExpireStaleBookingHoldsAction.",
            );
        }

        $this->info("Done. {$candidates->count()} booking(s) updated to Expired.");

        return self::SUCCESS;
    }
}