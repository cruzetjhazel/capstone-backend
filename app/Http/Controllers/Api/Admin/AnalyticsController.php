<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AccountType;
use App\Enums\BookingStatus;
use App\Enums\PhotographerApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PhotographerApplication;
use App\Models\ProfileView;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use ApiResponses;

    /** Report statuses that still need admin attention. */
    private const OPEN_REPORT_STATUSES = ['submitted', 'under_review'];

    /** Report statuses that count as finished. */
    private const DONE_REPORT_STATUSES = ['resolved', 'closed'];

    /**
     * GET /admin/analytics
     * One call that feeds every widget on the admin Analytics page.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $now = Carbon::now();

        return $this->success([
            'kpis' => $this->kpis($now),
            'growth' => $this->growth($now),
            'registrations' => $this->registrations($now),
            'user_distribution' => $this->userDistribution(),
            'reports_by_type' => $this->reportsByType(),
            'booking_status' => $this->bookingStatus(),
            'verification_pipeline' => $this->verificationPipeline(),
            'top_professionals' => $this->topProfessionals(),
            'health' => $this->health(),
            'reports_resolution' => $this->reportsResolution(),
            'marketplace' => $this->marketplace($now),
            'most_booked_packages' => $this->mostBookedPackages(),
            'most_favorited_packages' => $this->mostFavoritedPackages(),
            'most_favorited_photographers' => $this->mostFavoritedPhotographers(),
        ]);
    }

    private function kpis(Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $bookingsThisMonth = Booking::whereBetween('created_at', [$monthStart, $now])->count();
        $bookingsLastMonth = Booking::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        return [
            'total_users' => User::count(),
            'new_users_this_month' => User::where('created_at', '>=', $monthStart)->count(),
            'active_professionals' => $this->activeProfessionalsQuery()->count(),
            'verified_this_month' => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)
                ->where('reviewed_at', '>=', $monthStart)
                ->count(),
            'bookings_this_month' => $bookingsThisMonth,
            'bookings_change_pct' => $this->pctChange($bookingsLastMonth, $bookingsThisMonth),
            'open_reports' => Report::whereIn('status', self::OPEN_REPORT_STATUSES)->count(),
            'reports_opened_this_week' => Report::where('created_at', '>=', $now->copy()->subDays(7))->count(),
        ];
    }

    /**
     * Cumulative totals at the end of each of the last 6 months.
     * Professionals are counted from the approval time (reviewed_at), so this
     * is an approximation if an application is re-reviewed after approval.
     */
    private function growth(Carbon $now): array
    {
        return $this->lastSixMonths($now)->map(function (Carbon $month) {
            $end = $month->copy()->endOfMonth();

            return [
                'month' => $month->format('M'),
                'clients' => User::where('account_type', AccountType::Client)->where('created_at', '<=', $end)->count(),
                'professionals' => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)
                    ->where('reviewed_at', '<=', $end)
                    ->count(),
            ];
        })->values()->all();
    }

    private function registrations(Carbon $now): array
    {
        return $this->lastSixMonths($now)->map(function (Carbon $month) {
            $end = $month->copy()->endOfMonth();

            return [
                'month' => $month->format('M'),
                'new_clients' => User::where('account_type', AccountType::Client)
                    ->whereBetween('created_at', [$month, $end])
                    ->count(),
                'new_professionals' => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)
                    ->whereBetween('reviewed_at', [$month, $end])
                    ->count(),
            ];
        })->values()->all();
    }

    private function userDistribution(): array
    {
        return [
            'clients' => User::where('account_type', AccountType::Client)->count(),
            'verified_professionals' => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)->count(),
            'pending_professionals' => PhotographerApplication::where('status', PhotographerApplicationStatus::PendingReview)->count(),
            'admins' => User::where('account_type', AccountType::Administrator)->count(),
        ];
    }

    private function reportsByType(): array
    {
        return DB::table('reports')
            ->select('target_type', DB::raw('count(*) as total'))
            ->groupBy('target_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['type' => (string) $row->target_type, 'count' => (int) $row->total])
            ->all();
    }

    private function bookingStatus(): array
    {
        $rows = DB::table('bookings')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');
        if ($total === 0) {
            return [];
        }

        return $rows->map(fn ($row) => [
            'status' => (string) $row->status,
            'count' => (int) $row->total,
            'percentage' => (int) round(($row->total / $total) * 100),
        ])->all();
    }

    private function verificationPipeline(): array
    {
        return [
            'pending' => PhotographerApplication::where('status', PhotographerApplicationStatus::PendingReview)->count(),
            'approved' => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)->count(),
            'rejected' => PhotographerApplication::where('status', PhotographerApplicationStatus::Rejected)->count(),
        ];
    }

    private function topProfessionals(): array
    {
        $rows = DB::table('bookings')
            ->selectRaw(
                'photographer_id, count(*) as bookings, sum(case when status = ? then 1 else 0 end) as completed',
                [BookingStatus::Completed->value]
            )
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->groupBy('photographer_id')
            ->orderByDesc('bookings')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('photographer_id');

        $users = User::with('photographerApplication')->whereIn('id', $ids)->get()->keyBy('id');

        $ratings = Review::whereIn('photographer_id', $ids)
            ->selectRaw('photographer_id, avg(rating) as avg_rating')
            ->groupBy('photographer_id')
            ->pluck('avg_rating', 'photographer_id');

        return $rows->map(function ($row) use ($users, $ratings) {
            $user = $users->get($row->photographer_id);
            $avg = $ratings->get($row->photographer_id);

            return [
                'name' => $user?->photographerApplication?->business_name ?: ($user?->name ?? 'Unknown'),
                'bookings' => (int) $row->bookings,
                'completed' => (int) $row->completed,
                'rating' => $avg !== null ? round((float) $avg, 1) : null,
            ];
        })->all();
    }

    private function health(): array
    {
        $applications = PhotographerApplication::where('status', '!=', PhotographerApplicationStatus::Draft)->count();
        $approved = PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)->count();

        // Of the bookings that have ended, how many finished successfully.
        $completed = Booking::where('status', BookingStatus::Completed)->count();
        $cancelled = Booking::whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow])->count();

        $avgRating = Review::avg('rating');

        return [
            'verified_pct' => $applications > 0 ? (int) round(($approved / $applications) * 100) : null,
            'completion_rate' => ($completed + $cancelled) > 0 ? (int) round(($completed / ($completed + $cancelled)) * 100) : null,
            'avg_rating' => $avgRating !== null ? round((float) $avgRating, 1) : null,
        ];
    }

    private function reportsResolution(): array
    {
        $total = Report::count();
        $done = Report::whereIn('status', self::DONE_REPORT_STATUSES)->count();

        $resolved = Report::whereNotNull('resolved_at')->get(['created_at', 'resolved_at']);
        $avgHours = $resolved->isEmpty()
            ? null
            : round($resolved->avg(fn ($r) => $r->created_at->diffInMinutes($r->resolved_at, true)) / 60, 1);

        return [
            'resolved_pct' => $total > 0 ? (int) round(($done / $total) * 100) : null,
            'avg_resolution_hours' => $avgHours,
            'open' => Report::whereIn('status', self::OPEN_REPORT_STATUSES)->count(),
        ];
    }

    private function marketplace(Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        return [
            'profile_views_this_month' => ProfileView::whereBetween('viewed_on', [$monthStart, $now])->count(),
            'profile_views_last_month' => ProfileView::whereBetween('viewed_on', [$lastMonthStart, $lastMonthEnd])->count(),
        ];
    }

    private function mostBookedPackages(): array
    {
        $rows = DB::table('bookings')
            ->selectRaw('package_id, count(*) as total')
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->whereNotNull('package_id')
            ->groupBy('package_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $packages = \App\Models\Package::with('user.photographerApplication')
            ->whereIn('id', $rows->pluck('package_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($packages) {
            $pkg = $packages->get($row->package_id);

            return [
                'name' => $pkg?->name ?? 'Removed package',
                'photographer' => $pkg?->user?->photographerApplication?->business_name ?: ($pkg?->user?->name ?? 'Unknown'),
                'count' => (int) $row->total,
            ];
        })->all();
    }

    private function mostFavoritedPackages(): array
    {
        $rows = \App\Models\FavoritePackage::query()
            ->selectRaw('package_id, count(*) as total')
            ->groupBy('package_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $packages = \App\Models\Package::with('user.photographerApplication')
            ->whereIn('id', $rows->pluck('package_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($packages) {
            $pkg = $packages->get($row->package_id);

            return [
                'name' => $pkg?->name ?? 'Removed package',
                'photographer' => $pkg?->user?->photographerApplication?->business_name ?: ($pkg?->user?->name ?? 'Unknown'),
                'count' => (int) $row->total,
            ];
        })->all();
    }

    private function mostFavoritedPhotographers(): array
    {
        $rows = \App\Models\FavoritePhotographer::query()
            ->selectRaw('photographer_id, count(*) as total')
            ->groupBy('photographer_id')
            ->orderByDesc('total')
            ->orderBy('photographer_id')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $users = User::with('photographerApplication')
            ->whereIn('id', $rows->pluck('photographer_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($users) {
            $user = $users->get($row->photographer_id);
            $type = $user?->photographerApplication?->photographer_type?->value;

            return [
                'name' => $user?->photographerApplication?->business_name ?: ($user?->name ?? 'Unknown'),
                'photographer' => $type === 'studio' ? 'Studio' : 'Freelancer',
                'count' => (int) $row->total,
            ];
        })->all();
    }

    private function activeProfessionalsQuery()
    {
        return User::where('account_type', AccountType::Photographer)
            ->where('account_status', 'active')
            ->whereHas('photographerApplication', fn ($q) => $q->where('status', PhotographerApplicationStatus::Approved));
    }

    /** Oldest → newest: the current month and the 5 before it. */
    private function lastSixMonths(Carbon $now)
    {
        return collect(range(5, 0))->map(fn ($i) => $now->copy()->subMonthsNoOverflow($i)->startOfMonth());
    }

    private function pctChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
