<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'account_type',
        'account_status',
        'terms_accepted_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_type' => AccountType::class,
            'account_status' => AccountStatus::class,
            'terms_accepted_at' => 'datetime',
            'booking_hours_initialized' => 'boolean',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->account_type === AccountType::Administrator;
    }

    public function isPhotographer(): bool
    {
        return $this->account_type === AccountType::Photographer;
    }

    public function isClient(): bool
    {
        return $this->account_type === AccountType::Client;
    }

    public function isActive(): bool
    {
        return $this->account_status === AccountStatus::Active;
    }
    public function photographerApplication(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\PhotographerApplication::class);
    }

    public function isApprovedPhotographer(): bool
    {
        return $this->photographerApplication?->status === \App\Enums\PhotographerApplicationStatus::Approved;
    }
    public function photographerProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\PhotographerProfile::class);
    }

    public function portfolioImages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\PhotographerPortfolioImage::class);
    }

    public function activePortfolioImageCount(): int
    {
        return $this->portfolioImages()
            ->where('status', \App\Enums\PortfolioImageStatus::Active)
            ->count();
    }
    public function packages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Package::class);
    }

    public function addOns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\AddOn::class);
    }

    public function customPackageConfig(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\CustomPackageConfig::class);
    }

    public function customPackageComponents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\CustomPackageComponent::class);
    }

    public function activePackageCount(): int
    {
        return $this->packages()->where('status', \App\Enums\PackageStatus::Published)->count();
    }

    // A custom package only "counts" toward bookability once it's actually
    // usable: enabled, with a real price for the chosen pricing method
    // (fixed base fee, hourly rate, or per-day / per-person rate). Enabling
    // the toggle with fields left blank must not make a photographer look
    // bookable when the booking flow would break for any client who picks a
    // custom package.
    public function hasUsableCustomPackage(): bool
    {
        $config = $this->customPackageConfig;

        if (! $config || ! $config->enabled) {
            return false;
        }

        $model = $config->pricing_model ?: ($config->hourly_rate !== null ? 'hourly' : 'fixed');
        $priced = match ($model) {
            'hourly' => (float) $config->hourly_rate > 0,
            'per_day', 'per_person' => (float) $config->unit_rate > 0,
            default => (float) $config->base_fee > 0,
        };

        return $priced;
    }

    public function hasActivePackage(): bool
    {
        return $this->activePackageCount() > 0 || $this->hasUsableCustomPackage();
    }

    public function isEligibleForBusinessManagement(): bool
    {
        return $this->isPhotographer() && $this->isApprovedPhotographer();
    }
    public function availabilityWindows(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\AvailabilityWindow::class);
    }

    public function blockedDates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\BlockedDate::class);
    }
    public function bookingsAsClient(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Booking::class, 'client_id');
    }

    public function bookingsAsPhotographer(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Booking::class, 'photographer_id');
    }

    public function paymentConfig(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\PhotographerPaymentConfig::class);
    }

    public function paymentsAsClient(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Payment::class, 'client_id');
    }

    public function paymentsAsPhotographer(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Payment::class, 'photographer_id');
    }
    public function paymentReferences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\PhotographerPaymentReference::class, 'photographer_id');
    }
    public function clientProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\ClientProfile::class);
    }

    public function favoritePhotographers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\FavoritePhotographer::class, 'client_id');
    }

    public function hasOngoingBookings(): bool
    {
        $bookings = $this->isPhotographer() ? $this->bookingsAsPhotographer() : $this->bookingsAsClient();

        return $bookings
            ->whereIn('status', [
                \App\Enums\BookingStatus::Pending,
                \App\Enums\BookingStatus::Confirmed,
                \App\Enums\BookingStatus::Waitlisted,
            ])
            ->exists();
    }

    public function walkInClients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\WalkInClient::class, 'photographer_id');
    }

    public function reviews(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Review::class, 'photographer_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class)->latest();
    }
    public function bookingHours(): HasMany
    {
        return $this->hasMany(BookingHour::class);
    }
    
}