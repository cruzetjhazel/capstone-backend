<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\PublicPackageResource;
use App\Http\Resources\PublicAddOnResource;

class PhotographerPublicProfileResource extends JsonResource
{
    /**
     * $this = the User model (photographer). Only fields explicitly
     * intended for public display are included — never verification
     * documents, application review metadata, or account internals.
     */
    public function toArray(Request $request): array
    {
        $application = $this->photographerApplication;
        $profile = $this->photographerProfile;

        return [
            'id' => $this->id,
            'favorites_count' => (int) ($this->favorites_count ?? 0),
            'bookings_count' => (int) ($this->bookings_count ?? 0),
            'average_rating' => $this->reviews_avg_rating !== null ? round((float) $this->reviews_avg_rating, 1) : null,
            'reviews_count' => (int) ($this->reviews_count ?? 0),
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews
                ->sortByDesc('created_at')
                ->map(fn ($review) => [
                    'id' => $review->id,
                    'client_name' => $review->client?->name,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'reply' => $review->reply,
                    'replied_at' => $review->replied_at,
                    'created_at' => $review->created_at,
                ])
                ->values()
            ),
            'joined_at' => $application?->reviewed_at ?? $this->created_at,
            'is_bookable' => app(\App\Services\Photographer\BookabilityService::class)->isBookable($this->resource),
            'photographer_type' => $application?->photographer_type?->value,
            'is_freelancer' => (bool) $application?->is_freelancer,
            'is_studio_owner' => (bool) $application?->is_studio_owner,
            'business_name' => $application?->business_name,
            'location' => $application?->location,
            'coverage_area' => $application?->coverage_area,
            'years_active' => $application?->years_active,
            'team_size' => $application?->team_size,
            'shooting_types' => $application?->shooting_types ?? [],
            'services' => collect($application?->services ?? [])
                ->merge(
                    $application?->other_services
                        ? array_filter(array_map('trim', explode(',', $application->other_services)))
                        : []
                )
                ->unique()
                ->values()
                ->all(),
            'starting_price' => $this->packagePrices()->isNotEmpty() ? $this->packagePrices()->min() : $application?->price_min,
            'max_price' => $this->packagePrices()->isNotEmpty() ? $this->packagePrices()->max() : $application?->price_max,
            'style' => $profile?->style,
            'bio' => $profile?->bio,
            'profile_photo_url' => $profile?->profile_photo_path ? Storage::disk('public')->url($profile->profile_photo_path) : null,
            'cover_photo_url' => $profile?->cover_photo_path ? Storage::disk('public')->url($profile->cover_photo_path) : null,
            'social_links' => [
                'facebook' => $profile?->facebook,
                'instagram' => $profile?->instagram,
                'website' => $profile?->website,
            ],
            'phone' => $this->phone_number,
            'email' => $this->email,
            'portfolio' => PortfolioImageResource::collection(
                $this->portfolioImages
                    ->where('status', \App\Enums\PortfolioImageStatus::Active)
                    ->sortBy('sort_order')
                    ->values()
            ),
            'packages' => PublicPackageResource::collection(
                $this->packages->where('status', \App\Enums\PackageStatus::Published)
            ),
            'add_ons' => PublicAddOnResource::collection(
                $this->addOns->where('status', \App\Enums\AddOnStatus::Active)
            ),
        ];
    }

    private function packagePrices()
    {
        return $this->packages
            ->where('status', \App\Enums\PackageStatus::Published)
            ->pluck('price')
            ->map(fn ($price) => (float) $price);
    }
}