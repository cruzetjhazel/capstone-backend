<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AccountType;
use App\Enums\PhotographerApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use App\Enums\BookingStatus;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    use ApiResponses;

    /**
     * GET /admin/dashboard-stats
     * Single-call summary for the admin dashboard's stat cards.
     */
    public function stats(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        // Same visibility rule as the Admin Users list: photographers with a
        // draft (or no) application aren't counted yet.
        $totalUsers = User::query()
            ->where(function ($q) {
                $q->where('account_type', '!=', AccountType::Photographer)
                  ->orWhereHas('photographerApplication', fn ($q) => $q->where('status', '!=', PhotographerApplicationStatus::Draft));
            })
            ->count();

        $activeClients = User::where('account_type', AccountType::Client)
            ->where('account_status', 'active')
            ->count();

        $professionals = User::where('account_type', AccountType::Photographer)
            ->where('account_status', 'active')
            ->whereHas('photographerApplication', fn ($q) => $q->where('status', PhotographerApplicationStatus::Approved))
            ->count();

        $totalBookings = Booking::count();

        $pendingReviews = PhotographerApplication::where('status', PhotographerApplicationStatus::PendingReview)->count();

        // --- Analytics deltas ---
        // TODO: confirm against real Activity Log action names / PhotographerApplication
        // timestamp column before trusting these numbers — see note below.
        return $this->success([
            'total_users' => $totalUsers,
            'active_clients' => $activeClients,
            'professionals' => $professionals,
            'total_bookings' => $totalBookings,
            'pending_reviews' => $pendingReviews,
            'analytics' => $this->analyticsDeltas(),
        ]);
    }
        private function analyticsDeltas(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();
        $weekStart = $now->copy()->startOfWeek();
        $lastWeekStart = $weekStart->copy()->subWeek();
        $lastWeekEnd = $weekStart->copy()->subSecond();

        $completed = fn ($from, $to) => Booking::where('status', BookingStatus::Completed)
            ->whereBetween('event_date', [$from, $to])->count();
        $newClients = fn ($from, $to) => User::where('account_type', AccountType::Client)
            ->whereBetween('created_at', [$from, $to])->count();
        $verified = fn ($from, $to) => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)
            ->whereBetween('reviewed_at', [$from, $to])->count();

        return [
            'completed_bookings_change_pct' => $this->pctChange($completed($lastMonthStart, $lastMonthEnd), $completed($monthStart, $now)),
            'new_clients_change_pct' => $this->pctChange($newClients($lastWeekStart, $lastWeekEnd), $newClients($weekStart, $now)),
            'verified_professionals_change_pct' => $this->pctChange($verified($lastMonthStart, $lastMonthEnd), $verified($monthStart, $now)),
        ];
    }

    private function pctChange(float $previous, float $current): ?float
    {
        return $previous <= 0 ? null : round((($current - $previous) / $previous) * 100, 1);
    }
}
