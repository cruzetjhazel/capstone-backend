<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DEMO/TESTING ONLY. Fills the in-app notification feed (bell + Notifications
 * page) for the admin, the test clients, photographers and the photographer
 * applicants, so every kind of notification the UI can show is visible
 * without clicking through each flow by hand.
 *
 * Covers:
 *  - Admin: new / resubmitted photographer application, appeal, report,
 *    dispute (title contains "Dispute" -> links to /admin/reports),
 *    no-show review, refund request.
 *  - Photographer: application approved, booking request, payment to verify,
 *    cancellation / reschedule / modification / extension / budget requests,
 *    new review, payout-related updates.
 *  - Applicants: submitted, revision requested, rejected (can reapply),
 *    rejected (final).
 *  - Client: booking accepted ("Pay" action), payment verified / rejected,
 *    booking declined, reschedule / cancellation answers, service tracker
 *    stages, review reminder, refund, welcome.
 *  - Both payload shapes NotificationController understands: the new one
 *    (title + description) and the legacy one (dot "type" + "message").
 *  - A mix of read and unread, spread over the last 2 weeks.
 *
 * Rows are written straight to the `notifications` table, so no notification
 * class is needed. Every row is tagged "seed":"NotificationShowcaseSeeder",
 * so re-running only replaces its own rows and never touches real ones.
 *
 *   php artisan db:seed --class=NotificationShowcaseSeeder
 *
 * Run it AFTER the booking seeders (DatabaseSeeder does) so the
 * notifications can link to real bookings and applications.
 */
class NotificationShowcaseSeeder extends Seeder
{
    private const TAG = 'NotificationShowcaseSeeder';

    private int $created = 0;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('NotificationShowcaseSeeder only runs in local/testing.');

            return;
        }

        $this->clearPrevious();

        $this->seedAdmin();
        $this->seedClients();
        $this->seedPhotographers();
        $this->seedApplicants();

        $this->command?->info("Seeded {$this->created} notifications (admin, clients, photographers, applicants).");
    }

    // ------------------------------------------------------------------ admin

    private function seedAdmin(): void
    {
        $admin = User::where('account_type', AccountType::Administrator)->first();

        if (! $admin) {
            $this->command?->warn('No administrator found. Run AdminSeeder first.');

            return;
        }

        $pending = $this->userByEmail('applicant.pending@example.test');
        $revision = $this->userByEmail('applicant.revision@example.test');
        $booking = $this->bookingFor('client_id', $this->userByEmail('client@example.test'), 0);
        $booking2 = $this->bookingFor('client_id', $this->userByEmail('client@example.test'), 1);

        $this->add($admin, 'system', 'New photographer application',
            ($pending?->name ?? 'Bulan Bay Studio').' submitted an application for review. Open Verifications to review it.',
            ['application_id' => $this->applicationId($pending), 'class' => 'App\\Notifications\\PhotographerApplicationSubmittedNotification'],
            read: false, minutesAgo: 20);

        $this->add($admin, 'system', 'New photographer application',
            ($revision?->name ?? 'Kuya Jun Photos').' resubmitted their application after your revision request.',
            ['application_id' => $this->applicationId($revision), 'class' => 'App\\Notifications\\PhotographerApplicationSubmittedNotification'],
            read: false, minutesAgo: 95);

        $this->add($admin, 'system', 'New suspension appeal',
            'A suspended user submitted an appeal. Open Appeals to decide.', [], read: false, minutesAgo: 240);

        $this->add($admin, 'system', 'New report submitted',
            'A client reported a photographer for not showing up. Open Reports to review it.', [], read: false, minutesAgo: 400);

        $this->add($admin, 'booking', 'Dispute opened on a booking',
            'The photographer disputed a client\'s non-completion report. Review it in Reports.',
            ['booking_id' => $booking], read: false, minutesAgo: 60 * 20);

        $this->add($admin, 'booking', 'No-show review needed',
            'A booking was reported as a no-show and needs your decision.',
            ['booking_id' => $booking2], read: true, minutesAgo: 60 * 30);

        $this->add($admin, 'payment', 'Refund request pending',
            'A client requested a refund. Open Payments to record it.', [], read: true, minutesAgo: 60 * 48);

        $this->add($admin, 'system', 'Photographer application approved',
            'You approved an application. The photographer can now manage bookings.', [], read: true, minutesAgo: 60 * 24 * 6);
    }

    // ---------------------------------------------------------------- clients

    private function seedClients(): void
    {
        $emails = ['client@example.test', 'showcase.client@example.test'];

        foreach ($emails as $email) {
            $client = $this->userByEmail($email);

            if (! $client) {
                continue;
            }

            $b = fn (int $i) => $this->bookingFor('client_id', $client, $i);

            // Legacy payload shape (dot "type" + "message"): "booking.accepted" shows a Pay button.
            $this->addLegacy($client, 'booking.accepted',
                'Your booking request was accepted. Please pay the deposit to confirm your slot.',
                $b(0), read: false, minutesAgo: 15);

            $this->add($client, 'booking', 'Booking request sent',
                'Your request was sent to the photographer. You will be notified once they respond.',
                ['booking_id' => $b(1)], read: true, minutesAgo: 60 * 3);

            $this->add($client, 'payment', 'Payment verified',
                'The photographer verified your payment. Your booking is confirmed.',
                ['booking_id' => $b(2)], read: false, minutesAgo: 50);

            $this->add($client, 'payment', 'Payment rejected',
                'Your payment could not be verified. Please upload a clearer proof of payment.',
                ['booking_id' => $b(3), 'action' => 'pay'], read: false, minutesAgo: 130);

            $this->add($client, 'booking', 'Booking declined',
                'The photographer declined your request. You can book another photographer.',
                ['booking_id' => $b(4)], read: true, minutesAgo: 60 * 26);

            $this->add($client, 'booking', 'Reschedule approved',
                'Your reschedule request was approved. Check the new date in your booking.',
                ['booking_id' => $b(5)], read: true, minutesAgo: 60 * 30);

            $this->add($client, 'booking', 'Cancellation request answered',
                'The photographer responded to your cancellation request.',
                ['booking_id' => $b(6)], read: true, minutesAgo: 60 * 52);

            foreach ([
                ['Service update: Event Day', 'Your photographer marked the shoot as Event Day.', 7, false, 25],
                ['Service update: Editing', 'Your photos are now being edited.', 8, true, 60 * 40],
                ['Service update: Delivered', 'Your photos have been delivered. Please confirm completion.', 9, false, 70],
                ['Service completed', 'Your booking is completed. Leave a review for your photographer.', 10, true, 60 * 80],
            ] as [$title, $desc, $i, $read, $mins]) {
                $this->add($client, 'booking', $title, $desc, ['booking_id' => $b($i)], read: $read, minutesAgo: $mins);
            }

            $this->add($client, 'payment', 'Refund processed',
                'Your refund was recorded by the admin.', ['booking_id' => $b(11)], read: true, minutesAgo: 60 * 24 * 5);

            $this->add($client, 'system', 'Welcome to Bulan',
                'Browse photographers and send your first booking request. Verify your email to start booking.',
                [], read: true, minutesAgo: 60 * 24 * 13);
        }
    }

    // ---------------------------------------------------------- photographers

    private function seedPhotographers(): void
    {
        foreach (['showcase.photographer@example.test', 'wedding.showcase@example.test'] as $email) {
            $p = $this->userByEmail($email);

            if (! $p) {
                continue;
            }

            $b = fn (int $i) => $this->bookingFor('photographer_id', $p, $i);

            $this->add($p, 'system', 'Application approved',
                'Your photographer application was approved. You can now manage bookings and packages.',
                [], read: true, minutesAgo: 60 * 24 * 12);

            $this->add($p, 'booking', 'New booking request',
                'A client sent you a booking request. Accept or decline it.',
                ['booking_id' => $b(0)], read: false, minutesAgo: 10);

            $this->add($p, 'payment', 'Payment to verify',
                'A client uploaded a proof of payment. Please verify it.',
                ['booking_id' => $b(1)], read: false, minutesAgo: 45);

            $this->add($p, 'booking', 'Cancellation requested',
                'A client asked to cancel their booking. Approve or reject the request.',
                ['booking_id' => $b(2)], read: false, minutesAgo: 110);

            $this->add($p, 'booking', 'Reschedule requested',
                'A client asked to move their booking to another date.',
                ['booking_id' => $b(3)], read: false, minutesAgo: 200);

            $this->add($p, 'booking', 'Modification requested',
                'A client asked to change the package details of their booking.',
                ['booking_id' => $b(4)], read: true, minutesAgo: 60 * 8);

            $this->add($p, 'booking', 'Extension requested',
                'A client asked to extend their shoot hours.',
                ['booking_id' => $b(5)], read: true, minutesAgo: 60 * 14);

            $this->add($p, 'booking', 'Budget offer received',
                'A client proposed a different budget for their booking.',
                ['booking_id' => $b(6)], read: true, minutesAgo: 60 * 22);

            $this->add($p, 'booking', 'Dispute update',
                'The admin is reviewing a non-completion dispute on your booking.',
                ['booking_id' => $b(7)], read: true, minutesAgo: 60 * 36);

            $this->add($p, 'message', 'New review',
                'A client left a review on your profile. Open Reviews to reply.',
                [], read: true, minutesAgo: 60 * 60);
        }
    }

    // -------------------------------------------------------------- applicants

    private function seedApplicants(): void
    {
        $rows = [
            'applicant.draft@example.test' => [
                ['Finish your application', 'Your application is still a draft. Complete the steps and submit it for review.', false, 60 * 5],
            ],
            'applicant.pending@example.test' => [
                ['Application submitted', 'Your application is now Pending Review. We will notify you once the admin decides.', false, 30],
            ],
            'applicant.revision@example.test' => [
                ['Revision requested', 'Your government ID photo is blurry. Please upload a clearer photo and add your business permit.', false, 60 * 72],
            ],
            'applicant.rejected@example.test' => [
                ['Application rejected', 'The selfie does not match the photo on the government ID. You may fix this and apply again.', false, 60 * 24 * 8],
            ],
            'applicant.rejected-final@example.test' => [
                ['Application rejected', 'The documents submitted were found to be fake. This decision is final.', true, 60 * 24 * 18],
            ],
        ];

        foreach ($rows as $email => $items) {
            $user = $this->userByEmail($email);

            if (! $user) {
                continue;
            }

            foreach ($items as [$title, $desc, $read, $mins]) {
                $this->add($user, 'system', $title, $desc, [], read: $read, minutesAgo: $mins);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function clearPrevious(): void
    {
        DB::table('notifications')
            ->where('data', 'like', '%"seed":"'.self::TAG.'"%')
            ->delete();
    }

    private function userByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    private function applicationId(?User $user): ?int
    {
        return $user ? DB::table('photographer_applications')->where('user_id', $user->id)->value('id') : null;
    }

    /** The Nth booking (oldest first) for a client or photographer, or null if there are fewer. */
    private function bookingFor(string $column, ?User $user, int $index): ?int
    {
        if (! $user) {
            return null;
        }

        $id = DB::table('bookings')->where($column, $user->id)->orderBy('id')->skip($index)->limit(1)->value('id');

        return $id ? (int) $id : null;
    }

    /** New payload shape: title + description. */
    private function add(User $user, string $type, string $title, string $description, array $extra, bool $read, int $minutesAgo): void
    {
        $extra = array_filter($extra, fn ($v) => $v !== null);
        $class = $extra['class'] ?? 'App\\Notifications\\SeededNotification';
        unset($extra['class']);

        $this->insert($user, $class, array_merge([
            'type' => $type,
            'title' => $title,
            'description' => $description,
        ], $extra), $read, $minutesAgo);
    }

    /** Legacy payload shape: dot-notation "type" + "message" (no title). */
    private function addLegacy(User $user, string $dotType, string $message, ?int $bookingId, bool $read, int $minutesAgo): void
    {
        $this->insert($user, 'App\\Notifications\\SeededLegacyNotification', array_filter([
            'type' => $dotType,
            'message' => $message,
            'booking_id' => $bookingId,
        ], fn ($v) => $v !== null), $read, $minutesAgo);
    }

    private function insert(User $user, string $class, array $data, bool $read, int $minutesAgo): void
    {
        $at = now()->subMinutes($minutesAgo);
        $data['seed'] = self::TAG;

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => $class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode($data),
            'read_at' => $read ? $at->copy()->addMinutes(5) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $this->created++;
    }
}