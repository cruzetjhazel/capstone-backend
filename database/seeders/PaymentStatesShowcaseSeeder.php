<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PackageScheduleMode;
use App\Enums\PackageStatus;
use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\PaymentType;
use App\Enums\ServiceTrackerStatus;
use App\Models\Booking;
use App\Models\ClientProfile;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PhotographerPaymentReference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * DEMO/TESTING ONLY. Seeds bookings for the test client (client@example.test)
 * covering each payment state the Client Payments page can show. Every
 * booking's special_requests starts with "PAYMENT:" and names the state.
 * Re-running deletes and recreates them (idempotent).
 *
 *   php artisan db:seed --class=PaymentStatesShowcaseSeeder
 */
class PaymentStatesShowcaseSeeder extends Seeder
{
    private User $photographer;

    private User $client;

    private ?User $admin;

    /** @var array<string, Package> */
    private array $packages = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('PaymentStatesShowcaseSeeder only runs in local/testing.');

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

        // 1. Awaiting payment: accepted, nothing paid yet -> "Pay Now" with the full amount.
        $this->book('Awaiting payment (nothing paid yet)', 'Prenup Session', 4, [
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Pending,
            'hold_expires_at' => now()->addHours(18),
        ]);

        // 2. Payment In Progress: submitted, waiting for the GCash reference to be matched.
        $b = $this->book('Payment In Progress (submitted, awaiting verification)', 'Wedding Day Coverage', 55, $this->paid(PaymentPlan::Full, BookingPaymentStatus::PendingVerification, false));
        $this->payment($b, PaymentPlan::Full, (float) $b->total_price, [
            'matching_status' => PaymentMatchingStatus::PendingMatch,
            'photographer_payment_reference_id' => null,
        ]);

        // 3. Partial Payment: half paid online and verified, other half due onsite.
        $b = $this->book('Partial Payment (50% paid online)', 'Civil Wedding', 65, $this->paid(PaymentPlan::Half, BookingPaymentStatus::PartiallyPaid));
        $this->matchedPayment($b, PaymentPlan::Half, round((float) $b->total_price / 2, 2));

        // 4. Payment Verified: full payment that needed manual review, then approved by admin.
        $b = $this->book('Payment Verified (manually approved by admin)', 'Engagement Mini Session', 75, $this->paid(PaymentPlan::Full, BookingPaymentStatus::FullyPaid));
        $this->payment($b, PaymentPlan::Full, (float) $b->total_price, [
            'reference_number' => (string) random_int(1000000000000, 9999999999999),
            'photographer_payment_reference_id' => null,
            'matching_status' => PaymentMatchingStatus::ManuallyVerified,
            'verified_by' => $this->admin?->id,
            'verified_at' => now()->subHours(3),
            'verification_action' => 'verified',
            'verification_notes' => 'Reference confirmed manually against the photographer\'s GCash records.',
        ]);

        // 5. Balance Due: half paid online, event is close, remaining half still owed.
        $b = $this->book('Balance Due (half paid, event in 6 days)', 'Debut Celebration', 6, $this->paid(PaymentPlan::Half, BookingPaymentStatus::PartiallyPaid));
        $this->matchedPayment($b, PaymentPlan::Half, round((float) $b->total_price / 2, 2));

        // 6. Fully Paid: half online + remaining balance collected onsite, event done.
        $b = $this->book('Fully Paid (half online + balance onsite)', 'Civil Wedding', -15, array_merge(
            $this->paid(PaymentPlan::Half, BookingPaymentStatus::FullyPaid),
            ['status' => BookingStatus::Completed, 'service_status' => ServiceTrackerStatus::Delivered]
        ));
        $half = round((float) $b->total_price / 2, 2);
        $this->matchedPayment($b, PaymentPlan::Half, $half, now()->subDays(40)->toDateString());
        $this->payment($b, PaymentPlan::Half, round((float) $b->total_price - $half, 2), [
            'type' => PaymentType::Onsite,
            'method' => 'cash',
            'reference_number' => null,
            'photographer_payment_reference_id' => null,
            'matching_status' => PaymentMatchingStatus::ManuallyVerified,
            'verified_by' => $this->admin?->id,
            'verified_at' => now()->subDays(15),
            'verification_action' => 'verified',
            'payment_date' => now()->subDays(15)->toDateString(),
            'notes' => 'Remaining balance collected on-site.',
        ]);

        $count = Booking::where('client_id', $this->client->id)
            ->where('special_requests', 'like', 'PAYMENT:%')->count();
        $this->command?->info("Seeded {$count} payment-state bookings for {$this->client->email}.");
    }

    private function clientUser(): User
    {
        $email = env('TEST_CLIENT_EMAIL', 'client@example.test');
        $password = env('TEST_CLIENT_PASSWORD', 'password');

        $user = User::firstOrCreate(
            ['email' => $email],
            User::factory()->raw([
                'email' => $email,
                'name' => 'Test Client',
                'password' => bcrypt($password),
            ])
        );

        ClientProfile::firstOrCreate(
            ['user_id' => $user->id],
            ClientProfile::factory()->raw(['user_id' => $user->id])
        );

        return $user;
    }

    private function cleanup(): void
    {
        Booking::where('client_id', $this->client->id)
            ->where('photographer_id', $this->photographer->id)
            ->where('special_requests', 'like', 'PAYMENT:%')
            ->delete();
    }

    private function paid(PaymentPlan $plan, BookingPaymentStatus $status, bool $withTracker = true): array
    {
        return [
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => null,
            'payment_plan' => $plan,
            'payment_status' => $status,
            'service_status' => $withTracker ? ServiceTrackerStatus::Upcoming : null,
            'service_status_updated_at' => $withTracker ? now() : null,
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
            'special_requests' => 'PAYMENT: '.$label,
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