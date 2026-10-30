<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PackageScheduleMode;
use App\Enums\PackageStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\ReportRequestedAction;
use App\Enums\ReportSeverity;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\ClientProfile;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PhotographerPaymentReference;
use App\Models\Report;
use App\Models\ReportNote;
use App\Models\Review;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * DEMO/TESTING ONLY. Fills the gaps the other showcase seeders leave, with
 * exactly ONE row per scenario (no repeats):
 *
 *   - Reviews:  5 / 4 / 3 / 2 / 1 stars, with and without a photographer
 *               reply, and one review the photographer reported to admin.
 *   - Payments: the one payment state BookingStatesShowcaseSeeder lacks
 *               (full payment manually verified by admin).
 *   - Reports:  8 reports covering every target type, status (submitted,
 *               under review, resolved, closed), severity and requested
 *               action, from both clients and photographers.
 *   - Admin:    one account per moderation case (suspended + pending appeal,
 *               suspended + denied appeal, reinstated after approved appeal,
 *               self-deactivated).
 *
 * Booking and payment states live in BookingStatesShowcaseSeeder; this seeder
 * does not duplicate them. Rows are marked "MATRIX:" so re-running deletes and
 * recreates them (idempotent).
 *
 *   php artisan db:seed --class=ScenarioMatrixSeeder
 */
class ScenarioMatrixSeeder extends Seeder
{
    private User $photographer;

    private User $client;

    private ?User $admin;

    /** @var array<string, Package> */
    private array $packages = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('ScenarioMatrixSeeder only runs in local/testing.');

            return;
        }

        if (! User::where('email', CatalogShowcaseSeeder::WEDDING_EMAIL)->exists()) {
            $this->call(CatalogShowcaseSeeder::class);
        }

        $this->photographer = User::where('email', CatalogShowcaseSeeder::WEDDING_EMAIL)->firstOrFail();
        $this->admin = User::where('account_type', AccountType::Administrator)->first();
        $this->client = $this->clientUser();

        foreach (Package::where('user_id', $this->photographer->id)->where('status', PackageStatus::Published)->get() as $p) {
            $this->packages[$p->name] = $p;
        }

        $this->cleanup();

        $paymentBooking = $this->paymentScenario();
        $reviewedBooking = $this->reviewScenarios();
        $this->reportScenarios($reviewedBooking, $paymentBooking);
        $this->accountScenarios();

        $this->command?->info('Scenario matrix seeded: 1 payment state, 5 reviews, 8 reports, 4 account cases.');
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    private function paymentScenario(): Booking
    {
        $b = $this->book('Payment verified (manually approved by admin)', 'Engagement Mini Session', 75, $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid));

        $this->payment($b, PaymentPlan::Full, (float) $b->total_price, [
            'reference_number' => (string) random_int(1000000000000, 9999999999999),
            'photographer_payment_reference_id' => null,
            'matching_status' => PaymentMatchingStatus::ManuallyVerified,
            'verified_by' => $this->admin?->id,
            'verified_at' => now()->subHours(3),
            'verification_action' => 'verified',
            'verification_notes' => 'Reference confirmed manually against the photographer\'s GCash records.',
        ]);

        return $b;
    }

    // ------------------------------------------------------------------
    // Reviews: one per rating / reply / reported state
    // ------------------------------------------------------------------

    private function reviewScenarios(): Booking
    {
        $scenarios = [
            [
                'package' => 'Wedding Day Coverage', 'days' => -81,
                'label' => 'Review 5 stars (photographer replied)',
                'rating' => 5,
                'comment' => 'Every shot felt intentional and the gallery came back fast. Worth every peso.',
                'reply' => 'Thank you so much for trusting us with your day! It was a pleasure working with you.',
            ],
            [
                'package' => 'Civil Wedding', 'days' => -88,
                'label' => 'Review 4 stars (no reply yet)',
                'rating' => 4,
                'comment' => 'Really solid work. A couple of shots I wish were framed differently, but happy overall.',
            ],
            [
                'package' => 'Debut Celebration', 'days' => -95,
                'label' => 'Review 3 stars (photographer replied)',
                'rating' => 3,
                'comment' => 'Decent photos but it felt a bit rushed during the shoot.',
                'reply' => 'Thanks for the honest feedback. We are taking it into account for future bookings.',
            ],
            [
                'package' => 'Prenup Session', 'days' => -102,
                'label' => 'Review 2 stars (no reply yet)',
                'rating' => 2,
                'comment' => 'Photos arrived much later than promised, with no update from the studio.',
            ],
            [
                'package' => 'Engagement Mini Session', 'days' => -109,
                'label' => 'Review 1 star (reported by photographer)',
                'rating' => 1,
                'comment' => 'Terrible experience, they never showed any of the pictures they promised.',
                'report_reason' => 'This review contains false claims. All photos were delivered on time with proof of delivery.',
            ],
        ];

        $first = null;

        foreach ($scenarios as $s) {
            $b = $this->book($s['label'], $s['package'], $s['days'], [
                'status' => BookingStatus::Completed,
                'hold_expires_at' => null,
                'payment_plan' => PaymentPlan::Full,
                'payment_status' => BookingPaymentStatus::FullyPaid,
                'service_status' => ServiceTrackerStatus::Delivered,
                'service_status_updated_at' => now()->subDays(abs($s['days'])),
            ]);

            $this->matchedPayment($b, PaymentPlan::Full, (float) $b->total_price, now()->subDays(abs($s['days']) + 20)->toDateString());

            Review::unguarded(fn () => Review::create([
                'booking_id' => $b->id,
                'client_id' => $b->client_id,
                'photographer_id' => $b->photographer_id,
                'rating' => $s['rating'],
                'comment' => $s['comment'],
                'reply' => $s['reply'] ?? null,
                'replied_at' => isset($s['reply']) ? now()->subDays(abs($s['days']) - 2) : null,
                'report_reason' => $s['report_reason'] ?? null,
                'reported_at' => isset($s['report_reason']) ? now()->subDays(3) : null,
            ]));

            $first ??= $b;
        }

        return $first;
    }

    // ------------------------------------------------------------------
    // Reports: 8, every target type / status / severity / action covered
    // ------------------------------------------------------------------

    private function reportScenarios(Booking $reviewedBooking, Booking $paymentBooking): void
    {
        $booking = (string) $reviewedBooking->id;
        $payment = (string) $paymentBooking->id;
        $studio = (string) $this->photographer->id;
        $client = (string) $this->client->id;

        // From the client
        $this->report($this->client, ReportTargetType::Studio, $studio, 'Delivered photos do not match the portfolio quality shown.',
            ReportSeverity::High, ReportRequestedAction::Warn, ReportStatus::Submitted);
        $this->report($this->client, ReportTargetType::Booking, $booking, 'Photographer arrived two hours late on the event date.',
            ReportSeverity::Medium, ReportRequestedAction::Investigate, ReportStatus::UnderReview,
            'Reached out to both parties and asked for the booking messages.');
        $this->report($this->client, ReportTargetType::Payment, $payment, 'GCash reference number was not recognized by the system.',
            ReportSeverity::Urgent, ReportRequestedAction::Refund, ReportStatus::Resolved,
            'Reference confirmed manually. Payment marked as verified and the client was informed.');
        $this->report($this->client, ReportTargetType::Bug, null, 'Booking calendar shows the wrong available dates.',
            ReportSeverity::Low, ReportRequestedAction::Other, ReportStatus::Closed,
            'Could not reproduce. Closed after the reporter confirmed the dates look correct now.');

        // From the photographer
        $this->report($this->photographer, ReportTargetType::Client, $client, 'Client was rude and demanded extra services that were not booked.',
            ReportSeverity::Medium, ReportRequestedAction::Warn, ReportStatus::Submitted);
        $this->report($this->photographer, ReportTargetType::Booking, $booking, 'Client changed the venue the day before without telling me.',
            ReportSeverity::Low, ReportRequestedAction::Investigate, ReportStatus::UnderReview,
            'Waiting for the client\'s side of the story before deciding.');
        $this->report($this->photographer, ReportTargetType::Payment, $payment, 'Payment shows as paid but I cannot find it in my GCash records.',
            ReportSeverity::High, ReportRequestedAction::Investigate, ReportStatus::Resolved,
            'Checked the reference against both GCash records. The payment is valid.');
        $this->report($this->photographer, ReportTargetType::Other, null, 'A review about my studio contains false statements.',
            ReportSeverity::Urgent, ReportRequestedAction::RemoveReview, ReportStatus::Closed,
            'Reviews cannot be removed. The photographer\'s reply stays on the review.');
    }

    private function report(
        User $reporter,
        ReportTargetType $target,
        ?string $referenceId,
        string $reason,
        ReportSeverity $severity,
        ReportRequestedAction $action,
        ReportStatus $status,
        ?string $adminNote = null,
    ): void {
        $closed = in_array($status, [ReportStatus::Resolved, ReportStatus::Closed], true);

        $report = Report::factory()->create([
            'reporter_id' => $reporter->id,
            'target_type' => $target->value,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'severity' => $severity,
            'details' => 'MATRIX: '.$reason,
            'requested_action' => $action,
            'status' => $status,
            'resolved_at' => $closed ? now()->subDays(2) : null,
        ]);

        if ($adminNote !== null) {
            ReportNote::factory()->create([
                'report_id' => $report->id,
                'admin_id' => $this->admin?->id,
                'note' => $adminNote,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Admin moderation cases
    // ------------------------------------------------------------------

    private function accountScenarios(): void
    {
        // 1. Suspended, appeal waiting for admin
        $u = $this->matrixUser('suspended-pending', 'Matrix Suspended (appeal pending)', false);
        $this->setAccount($u, AccountStatus::Suspended, 'Multiple reports of abusive messages to photographers.', now()->subDays(3));
        $this->appeal($u, 'pending', 'I was frustrated about a cancelled booking. I apologize and it will not happen again.', now()->subDay());

        // 2. Suspended, appeal denied
        $u = $this->matrixUser('suspended-denied', 'Matrix Suspended (appeal denied)', true);
        $this->setAccount($u, AccountStatus::Suspended, 'Repeated no-shows reported by clients and upheld by admin.', now()->subDays(10));
        $this->appeal($u, 'denied', 'The missed events were caused by a family emergency.', now()->subDays(8),
            'The no-shows were reviewed and upheld. The suspension stays in place.', now()->subDays(6));

        // 3. Reinstated after an approved appeal
        $u = $this->matrixUser('reinstated', 'Matrix Reinstated (appeal approved)', false);
        $this->setAccount($u, AccountStatus::Active, null, null);
        $this->appeal($u, 'approved', 'The reports were a misunderstanding. Please review the chat history.', now()->subDays(14),
            'Chat history reviewed. The reports were not supported, so your account is active again.', now()->subDays(12));

        // 4. Deactivated by the user (no appeal)
        $u = $this->matrixUser('deactivated', 'Matrix Deactivated (by user)', false);
        $u->forceFill([
            'account_status' => AccountStatus::Deactivated,
            'deactivated_at' => now()->subDays(5),
            'suspension_reason' => null,
            'suspended_at' => null,
        ])->save();
    }

    private function matrixUser(string $key, string $name, bool $photographer): User
    {
        $email = "matrix.{$key}@example.test";
        $factory = $photographer ? User::factory()->photographer() : User::factory();

        $user = User::firstOrCreate(
            ['email' => $email],
            $factory->raw(['email' => $email, 'name' => $name, 'password' => bcrypt('password')])
        );

        SuspensionAppeal::where('user_id', $user->id)->delete();

        return $user;
    }

    private function setAccount(User $user, AccountStatus $status, ?string $reason, ?Carbon $suspendedAt): void
    {
        $user->forceFill([
            'account_status' => $status,
            'suspension_reason' => $reason,
            'suspended_at' => $suspendedAt,
            'deactivated_at' => null,
        ])->save();
    }

    private function appeal(User $user, string $status, string $message, Carbon $createdAt, ?string $response = null, ?Carbon $decidedAt = null): void
    {
        SuspensionAppeal::unguarded(fn () => SuspensionAppeal::create([
            'user_id' => $user->id,
            'message' => $message,
            'status' => $status,
            'admin_response' => $response,
            'decided_by' => $decidedAt ? $this->admin?->id : null,
            'decided_at' => $decidedAt,
            'created_at' => $createdAt,
            'updated_at' => $decidedAt ?? $createdAt,
        ]));
    }

    // ------------------------------------------------------------------
    // Helpers (same shape as the other showcase seeders)
    // ------------------------------------------------------------------

    private function clientUser(): User
    {
        $email = env('TEST_CLIENT_EMAIL', 'client@example.test');

        $user = User::firstOrCreate(
            ['email' => $email],
            User::factory()->raw([
                'email' => $email,
                'name' => 'Test Client',
                'password' => bcrypt(env('TEST_CLIENT_PASSWORD', 'password')),
            ])
        );

        ClientProfile::firstOrCreate(
            ['user_id' => $user->id],
            ClientProfile::factory()->raw(['user_id' => $user->id])
        );

        return $user;
    }

    /** Wipe a previous run so re-seeding never duplicates. */
    private function cleanup(): void
    {
        Report::where('details', 'like', 'MATRIX:%')->delete();

        // Deleting the bookings also removes their reviews and payments.
        Booking::where('photographer_id', $this->photographer->id)
            ->where('special_requests', 'like', 'MATRIX:%')
            ->delete();
    }

    private function paid(PaymentPlan $plan, BookingPaymentStatus $status): array
    {
        return [
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => null,
            'payment_plan' => $plan,
            'payment_status' => $status,
            'service_status' => ServiceTrackerStatus::Upcoming,
            'service_status_updated_at' => now(),
        ];
    }

    private function book(string $label, string $packageName, int $daysOut, array $over = []): Booking
    {
        $package = $this->packages[$packageName]
            ?? throw new \RuntimeException("Package '{$packageName}' not found. Run CatalogShowcaseSeeder first.");

        $open = $package->schedule_mode === PackageScheduleMode::Open;
        $start = '09:00';
        $minutes = $open ? null : $package->duration_minutes;
        $end = $minutes ? Carbon::parse($start)->addMinutes($minutes)->format('H:i') : null;

        return Booking::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'package_id' => $package->id,
            'is_custom_package' => false,
            'package_snapshot' => [
                'name' => $package->name,
                'price' => (float) $package->price,
                'duration_minutes' => $package->duration_minutes,
                'schedule_mode' => $open ? 'open' : 'timed',
                'buffer_minutes' => $package->buffer_minutes,
            ],
            'add_ons_snapshot' => [],
            'event_type' => str_contains($packageName, 'Debut') ? 'debut' : 'wedding',
            'event_date' => now()->addDays($daysOut)->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => $minutes,
            'subtotal' => $package->price,
            'total_price' => $package->price,
            'status' => BookingStatus::Pending,
            'hold_expires_at' => now()->addHours(20),
            'special_requests' => 'MATRIX: '.$label,
        ], $over));
    }

    private function matchedPayment(Booking $b, PaymentPlan $plan, float $amount, ?string $date = null): Payment
    {
        $date ??= now()->toDateString();

        $ref = PhotographerPaymentReference::factory()->used()->create([
            'photographer_id' => $b->photographer_id,
            'amount_received' => $amount,
            'payment_date' => $date,
        ]);

        return $this->payment($b, $plan, $amount, [
            'reference_number' => $ref->reference_number,
            'photographer_payment_reference_id' => $ref->id,
            'matching_status' => PaymentMatchingStatus::Matched,
            'verified_by' => $this->admin?->id,
            'verified_at' => Carbon::parse($date)->addHour(),
            'verification_action' => 'verified',
            'payment_date' => $date,
        ]);
    }

    private function payment(Booking $b, PaymentPlan $plan, float $amount, array $over = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'booking_id' => $b->id,
            'client_id' => $b->client_id,
            'photographer_id' => $b->photographer_id,
            'plan' => $plan,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ], $over));
    }
}