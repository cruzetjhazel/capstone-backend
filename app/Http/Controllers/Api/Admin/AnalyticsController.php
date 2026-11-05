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
     * GET /admin/analytics?year=2026
     * One call that feeds every widget on the admin Analytics page.
     * No year = All Time. Each section documents the date field it uses.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        $now = Carbon::now();
        $year = $request->filled('year') ? (int) $request->input('year') : null;
        [$start, $end] = $this->bounds($year);
        [$growth, $growthGranularity] = $this->growth($now, $year);

        return $this->success([
            'year' => $year,
            'available_years' => $this->availableYears($now),
            'notes' => $this->notes(),
            'kpis' => $this->kpis($year, $start, $end),
            'growth' => $growth,
            'growth_granularity' => $growthGranularity,
            'user_distribution' => $this->userDistribution($start, $end),
            'reports_by_type' => $this->reportsByType($start, $end),
            'booking_status' => $this->bookingStatus($start, $end),
            'verification_pipeline' => $this->verificationPipeline($start, $end),
            'top_professionals' => $this->topProfessionals($start, $end),
            'health' => $this->health($start, $end),
            'reports_resolution' => $this->reportsResolution($start, $end),
            'marketplace' => $this->marketplace($year, $start, $end),
            'most_booked_packages' => $this->mostBookedPackages($start, $end),
            // Favorite rows are deleted when un-favorited, so there is no yearly history: always current.
            'most_favorited_packages' => $this->mostFavoritedPackages(),
            'most_favorited_photographers' => $this->mostFavoritedPhotographers(),
        ]);
    }

    /**
     * users.created_at (registrations), photographer_applications.reviewed_at (approvals),
     * bookings.created_at (booking requests), reports.created_at (reports).
     * active_professionals and open_reports are current snapshots.
     */
    private function kpis(?int $year, ?Carbon $start, ?Carbon $end): array
    {
        $bookings = $this->within(Booking::query(), 'created_at', $start, $end)->count();

        $previousBookings = null;
        if ($year) {
            [$prevStart, $prevEnd] = $this->bounds($year - 1);
            $previousBookings = Booking::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        }

        return [
            'new_users' => $this->within(User::query(), 'created_at', $start, $end)->count(),
            'total_users' => User::query()->when($end, fn ($q) => $q->where('created_at', '<=', $end))->count(),
            'approved_professionals' => $this->within(
                PhotographerApplication::where('status', PhotographerApplicationStatus::Approved),
                'reviewed_at', $start, $end
            )->distinct()->count('user_id'),
            'active_professionals' => $this->activeProfessionalsQuery()->count(),
            'bookings' => $bookings,
            'bookings_change_pct' => $previousBookings === null ? null : $this->pctChange($previousBookings, $bookings),
            'reports' => $this->within(Report::query(), 'created_at', $start, $end)->count(),
            'open_reports' => Report::whereIn('status', self::OPEN_REPORT_STATUSES)->count(),
        ];
    }

    /**
     * Running totals. Clients by users.created_at, photographers by approval date
     * (reviewed_at, one per user). Year selected: Jan..Dec of that year (cumulative
     * from the beginning). All Time: monthly if the history is 24 months or less,
     * otherwise yearly.
     *
     * @return array{0: array, 1: string}
     */
    private function growth(Carbon $now, ?int $year): array
    {
        if ($year) {
            $points = $this->monthsOfYear($now, $year)
                ->map(fn (Carbon $m) => ['label' => $m->format('M'), 'end' => $m->copy()->endOfMonth()]);
            $granularity = 'month';
        } else {
            $first = User::min('created_at');
            // Never fewer than the last 6 months, so a young platform still draws a line instead of one dot.
            $sixMonthsAgo = $now->copy()->subMonthsNoOverflow(5)->startOfMonth();
            $startMonth = ($first ? Carbon::parse($first) : $now->copy())->startOfMonth()->min($sixMonthsAgo);

            if ($startMonth->diffInMonths($now, true) <= 24) {
                $points = collect();
                for ($cursor = $startMonth->copy(); $cursor->lte($now); $cursor->addMonthNoOverflow()) {
                    $points->push(['label' => $cursor->format('M Y'), 'end' => $cursor->copy()->endOfMonth()]);
                }
                $granularity = 'month';
            } else {
                $points = collect(range($startMonth->year, $now->year))
                    ->map(fn (int $y) => ['label' => (string) $y, 'end' => Carbon::create($y, 12, 31)->endOfDay()]);
                $granularity = 'year';
            }
        }

        $rows = $points->map(fn (array $p) => [
            'label' => $p['label'],
            'clients' => User::where('account_type', AccountType::Client)->where('created_at', '<=', $p['end'])->count(),
            'professionals' => PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)
                ->where('reviewed_at', '<=', $p['end'])
                ->distinct()->count('user_id'),
        ])->values()->all();

        return [$rows, $granularity];
    }

    /** Accounts registered in the period (users.created_at), grouped by account type. */
    private function userDistribution(?Carbon $start, ?Carbon $end): array
    {
        $count = fn (AccountType $type) => $this->within(User::where('account_type', $type), 'created_at', $start, $end)->count();

        return [
            'clients' => $count(AccountType::Client),
            'photographers' => $count(AccountType::Photographer),
            'admins' => $count(AccountType::Administrator),
        ];
    }

    /** Reports submitted in the period (reports.created_at), by type. */
    private function reportsByType(?Carbon $start, ?Carbon $end): array
    {
        return $this->within(DB::table('reports'), 'created_at', $start, $end)
            ->select('target_type', DB::raw('count(*) as total'))
            ->groupBy('target_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['type' => (string) $row->target_type, 'count' => (int) $row->total])
            ->all();
    }

    /** Booking requests created in the period (bookings.created_at), grouped by CURRENT status. */
    private function bookingStatus(?Carbon $start, ?Carbon $end): array
    {
        $rows = $this->within(DB::table('bookings'), 'created_at', $start, $end)
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

    /**
     * pending = current queue (snapshot). approved/rejected = applications whose
     * latest review date (reviewed_at) is in the period AND whose status is still that decision.
     */
    private function verificationPipeline(?Carbon $start, ?Carbon $end): array
    {
        $decisions = fn (PhotographerApplicationStatus $status) => $this->within(
            PhotographerApplication::where('status', $status), 'reviewed_at', $start, $end
        )->distinct()->count('user_id');

        return [
            'pending' => PhotographerApplication::where('status', PhotographerApplicationStatus::PendingReview)->count(),
            'approved' => $decisions(PhotographerApplicationStatus::Approved),
            'rejected' => $decisions(PhotographerApplicationStatus::Rejected),
        ];
    }

    /** Confirmed + completed bookings created in the period. Rating is all-time. */
    private function topProfessionals(?Carbon $start, ?Carbon $end): array
    {
        $rows = $this->within(DB::table('bookings'), 'created_at', $start, $end)
            ->selectRaw(
                'photographer_id, count(*) as bookings, sum(case when status = ? then 1 else 0 end) as completed',
                [BookingStatus::Completed->value]
            )
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->groupBy('photographer_id')
            ->orderByDesc('bookings')
            ->orderBy('photographer_id')
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

    /**
     * completion_rate: bookings created in the period. verified_pct and avg_rating: all-time/current.
     */
    private function health(?Carbon $start, ?Carbon $end): array
    {
        $applications = PhotographerApplication::where('status', '!=', PhotographerApplicationStatus::Draft)->count();
        $approved = PhotographerApplication::where('status', PhotographerApplicationStatus::Approved)->count();

        $completed = $this->within(Booking::where('status', BookingStatus::Completed), 'created_at', $start, $end)->count();
        $cancelled = $this->within(
            Booking::whereIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow]), 'created_at', $start, $end
        )->count();

        $avgRating = Review::avg('rating');

        return [
            'verified_pct' => $applications > 0 ? (int) round(($approved / $applications) * 100) : null,
            'completion_rate' => ($completed + $cancelled) > 0 ? (int) round(($completed / ($completed + $cancelled)) * 100) : null,
            'avg_rating' => $avgRating !== null ? round((float) $avgRating, 1) : null,
        ];
    }

    /** Everything here is about reports CREATED in the period (reports.created_at / resolved_at). */
    private function reportsResolution(?Carbon $start, ?Carbon $end): array
    {
        $total = $this->within(Report::query(), 'created_at', $start, $end)->count();
        $done = $this->within(Report::whereIn('status', self::DONE_REPORT_STATUSES), 'created_at', $start, $end)->count();
        $open = $this->within(Report::whereIn('status', self::OPEN_REPORT_STATUSES), 'created_at', $start, $end)->count();

        $resolved = $this->within(Report::whereNotNull('resolved_at'), 'created_at', $start, $end)
            ->get(['created_at', 'resolved_at']);
        $avgHours = $resolved->isEmpty()
            ? null
            : round($resolved->avg(fn ($r) => $r->created_at->diffInMinutes($r->resolved_at, true)) / 60, 1);

        return [
            'resolved_pct' => $total > 0 ? (int) round(($done / $total) * 100) : null,
            'avg_resolution_hours' => $avgHours,
            'open' => $open,
        ];
    }

    /**
     * profile_views rows are one unique visitor per photographer per day (viewed_on).
     * Year selected: that year vs the previous year. All Time: total only.
     */
    private function marketplace(?int $year, ?Carbon $start, ?Carbon $end): array
    {
        $views = $this->within(ProfileView::query(), 'viewed_on', $start, $end)->count();

        if (! $year) {
            return ['views' => $views, 'previous_views' => null, 'change_pct' => null];
        }

        [$prevStart, $prevEnd] = $this->bounds($year - 1);
        $previous = ProfileView::whereBetween('viewed_on', [$prevStart, $prevEnd])->count();

        return [
            'views' => $views,
            'previous_views' => $previous,
            'change_pct' => $this->pctChange($previous, $views),
        ];
    }

    /** Confirmed + completed bookings created in the period, by package. */
    private function mostBookedPackages(?Carbon $start, ?Carbon $end): array
    {
        $rows = $this->within(DB::table('bookings'), 'created_at', $start, $end)
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

    /** Current favorites only (rows are deleted when removed). */
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

    /** Current favorites only (rows are deleted when removed). */
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

    /** Honest caveats about data that cannot be placed in a year. */
    private function notes(): array
    {
        $notes = [];

        $undated = PhotographerApplication::whereIn('status', [
            PhotographerApplicationStatus::Approved,
            PhotographerApplicationStatus::Rejected,
        ])->whereNull('reviewed_at')->count();

        if ($undated > 0) {
            $notes[] = "{$undated} approved or rejected application(s) have no review date, so they appear in All Time but not in any single year or in the growth chart.";
        }

        return $notes;
    }

    /** Years that have data, newest first (earliest user, booking, report or profile view → current year). */
    private function availableYears(Carbon $now): array
    {
        $earliest = collect([
            User::min('created_at'),
            Booking::min('created_at'),
            Report::min('created_at'),
            ProfileView::min('viewed_on'),
        ])->filter()->map(fn ($d) => Carbon::parse($d)->year);

        $startYear = min($earliest->min() ?? $now->year, $now->year);

        return range($now->year, $startYear);
    }

    /** January → December of a year (only up to the current month for the current year). */
    private function monthsOfYear(Carbon $now, int $year)
    {
        $last = $year < $now->year ? 12 : ($year === $now->year ? $now->month : 0);

        return collect($last > 0 ? range(1, $last) : [])
            ->map(fn ($m) => Carbon::create($year, $m, 1)->startOfMonth());
    }

    /** [start, end] of a calendar year, or [null, null] for All Time. */
    private function bounds(?int $year): array
    {
        if (! $year) {
            return [null, null];
        }

        $start = Carbon::create($year, 1, 1)->startOfDay();

        return [$start, $start->copy()->endOfYear()];
    }

    /** Restrict a query (Eloquent or DB::table) to the period; no-op for All Time. */
    private function within($query, string $column, ?Carbon $start, ?Carbon $end)
    {
        return $query->when($start, fn ($q) => $q->whereBetween($column, [$start, $end]));
    }

    private function activeProfessionalsQuery()
    {
        return User::where('account_type', AccountType::Photographer)
            ->where('account_status', 'active')
            ->whereHas('photographerApplication', fn ($q) => $q->where('status', PhotographerApplicationStatus::Approved));
    }

    private function pctChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}