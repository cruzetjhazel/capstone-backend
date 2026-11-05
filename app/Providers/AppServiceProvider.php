<?php

namespace App\Providers;

use App\Models\Booking;
use App\Observers\BookingObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit registration as a fallback for the #[ObservedBy] attribute
        // on the Booking model, which only takes effect on Laravel 11+.
        // Auto-advances service_status to Upcoming once payment settles —
        // see BookingObserver::saved().
        Booking::observe(BookingObserver::class);

        // One password rule everywhere: at least 8 characters, with letters and numbers.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());
                if (app()->environment('production')) {
            URL::forceScheme('https');
        }
        // Login session: expire after 24 hours of INACTIVITY (not 24h after
        // login). Sanctum stamps last_used_at on every authenticated request,
        // so each request slides the window forward. A token that has never
        // been used is measured from its creation time. Applies to every
        // token: password login, registration and Google login.
        // Change the window with SESSION_INACTIVITY_HOURS in .env.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

            return $lastActivity->gt(now()->subHours((int) env('SESSION_INACTIVITY_HOURS', 24)));
        });
        // Laravel's default ResetPassword notification links to a Blade route this
        // API-only backend doesn't have. Point it at the React frontend's actual
        // /reset-password page instead. FRONTEND_URL is read directly via env()
        // rather than through config/app.php since that file wasn't part of this
        // change — set FRONTEND_URL in .env; if you ever run `php artisan
        // config:cache` in production, switch this to a config('app.frontend_url')
        // lookup backed by a config/app.php entry instead, since cached config
        // freezes env() reads.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = rtrim(config('frontend.url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($notifiable->getEmailForPasswordReset());
        });

        // Brute-force / credential-stuffing protection for auth endpoints
        // (login, register, forgot-password) — none of these had any rate
        // limiting before. Keyed by IP + submitted email so one attacker
        // can't lock out a real user's email by hammering it from many IPs
        // while still being generous enough for normal typos. Applied via
        // throttle:auth on the routes themselves (see routes/api.php).
        RateLimiter::for('auth', function (Request $request) {
            $emailKey = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($request->ip().'|'.$emailKey),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Registration: per-minute limit plus an hourly cap per IP.
        RateLimiter::for('register', function (Request $request) {
            $emailKey = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(5)->by($request->ip().'|'.$emailKey),
                Limit::perHour(20)->by($request->ip()),
            ];
        });
    }
}