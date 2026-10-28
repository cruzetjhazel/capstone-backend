<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\ProfileView;
use App\Models\Review;
use App\Traits\ApiResponses;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    use ApiResponses;

    /**
     * Bookings/revenue only count toward analytics once the work is
     * actually confirmed or completed — not while still pending, rejected,
     * or cancelled.
     */
    private const REVENUE_STATUSES = [BookingStatus::Confirmed, BookingStatus::Completed];

    public function index()
    {
        $photographerId = auth()->id();

        return $this->success([
            'kpis' => $this->kpis($photographerId),
            'monthly' => $this->monthlyTrend($photographerId),
            'service_mix' => $this->serviceMix($photographerId),
            'top_clients' => $this->topClients($photographerId),
            'most_booked_packages' => $this->mostBookedPackages($photographerId),
            'most_favorited_packages' => $this->mostFavoritedPackages($photographerId),
        ]);
    }

    private function kpis(int $photographerId): array
    {
        $thisMonthStart = Carbon::now()->startOfMonth();
        $lastMonthStart = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        $thisMonthRevenue = (float) Payment::where('photographer_id', $photographerId)
            ->whereNotNull('verified_at')
            ->whereBetween('payment_date', [$thisMonthStart, Carbon::now()])
            ->sum('amount');

        $lastMonthRevenue = (float) Payment::where('photographer_id', $photographerId)
            ->whereNotNull('verified_at')
            ->whereBetween('payment_date', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $thisMonthBookings = Booking::where('photographer_id', $photographerId)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereBetween('event_date', [$thisMonthStart, Carbon::now()])
            ->count();

        $lastMonthBookings = Booking::where('photographer_id', $photographerId)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereBetween('event_date', [$lastMonthStart, $lastMonthEnd])
            ->count();

        $avgRating = Review::where('photographer_id', $photographerId)->avg('rating');

        $thisMonthViews = ProfileView::where('photographer_id', $photographerId)
            ->whereBetween('viewed_on', [$thisMonthStart, Carbon::now()])
            ->count();

        $lastMonthViews = ProfileView::where('photographer_id', $photographerId)
            ->whereBetween('viewed_on', [$lastMonthStart, $lastMonthEnd])
            ->count();

        return [
            'total_revenue' => $thisMonthRevenue,
            'revenue_change_pct' => $this->pctChange($lastMonthRevenue, $thisMonthRevenue),
            'bookings_this_month' => $thisMonthBookings,
            'bookings_change' => $thisMonthBookings - $lastMonthBookings,
            'avg_rating' => $avgRating ? round($avgRating, 1) : null,
            'profile_views_this_month' => $thisMonthViews,
            'profile_views_change_pct' => $this->pctChange($lastMonthViews, $thisMonthViews),
        ];
    }

    private function monthlyTrend(int $photographerId): array
    {
        return collect(range(5, 0))
            ->map(fn ($i) => Carbon::now()->subMonthsNoOverflow($i)->startOfMonth())
            ->map(function (Carbon $month) use ($photographerId) {
                $end = $month->copy()->endOfMonth();

                $revenue = (float) Payment::where('photographer_id', $photographerId)
                    ->whereNotNull('verified_at')
                    ->whereBetween('payment_date', [$month, $end])
                    ->sum('amount');

                $bookings = Booking::where('photographer_id', $photographerId)
                    ->whereIn('status', self::REVENUE_STATUSES)
                    ->whereBetween('event_date', [$month, $end])
                    ->count();

                $views = ProfileView::where('photographer_id', $photographerId)
                    ->whereBetween('viewed_on', [$month, $end])
                    ->count();

                return [
                    'month' => $month->format('M'),
                    'revenue' => $revenue,
                    'bookings' => $bookings,
                    'views' => $views,
                ];
            })
            ->values()
            ->all();
    }

    private function serviceMix(int $photographerId): array
    {
        $bookings = Booking::where('photographer_id', $photographerId)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->get(['event_type', 'custom_event_type']);

        $total = $bookings->count();
        if ($total === 0) {
            return [];
        }

        return $bookings
            ->groupBy(fn ($b) => $b->custom_event_type ?: $b->event_type)
            ->map(fn ($group, $name) => [
                'name' => $name,
                'value' => round(($group->count() / $total) * 100),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    private function topClients(int $photographerId): array
    {
        return Booking::where('photographer_id', $photographerId)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->with('client:id,name')
            ->get()
            ->groupBy('client_id')
            ->map(fn ($group) => [
                'name' => $group->first()->client->name,
                'bookings' => $group->count(),
                'spent' => (float) $group->sum('total_price'),
            ])
            ->sortByDesc('spent')
            ->take(4)
            ->values()
            ->all();
    }

    private function mostFavoritedPackages(int $photographerId): array
    {
        $packages = \App\Models\Package::where('user_id', $photographerId)
            ->where('status', '!=', \App\Enums\PackageStatus::Archived)
            ->pluck('name', 'id');

        if ($packages->isEmpty()) {
            return [];
        }

        return \App\Models\FavoritePackage::whereIn('package_id', $packages->keys())
            ->selectRaw('package_id, count(*) as total')
            ->groupBy('package_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => $packages[$r->package_id], 'count' => (int) $r->total])
            ->all();
    }

    private function mostBookedPackages(int $photographerId): array
    {
        $rows = Booking::where('photographer_id', $photographerId)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->whereNotNull('package_id')
            ->selectRaw('package_id, count(*) as total')
            ->groupBy('package_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = \App\Models\Package::whereIn('id', $rows->pluck('package_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'name' => $names[$r->package_id] ?? 'Removed package',
            'count' => (int) $r->total,
        ])->all();
    }

    private function pctChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}