<?php

namespace App\Models;


use App\Enums\PhotographerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\PhotographerApplicationStatus;


class PhotographerApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'photographer_type',
        'is_freelancer',
        'is_studio_owner',
        'status',
        'business_name',
        'location',
        'years_active',
        'team_size',
        'services',
        'other_services',
        'coverage_area',
        'shooting_types',
        'price_min',
        'price_max',
        'government_id_path',
        'selfie_with_id_path',
        'business_permit_path',
        'additional_document_paths',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
        'revision_notes',
        'rejection_reason',
        'can_reapply',
    ];

    protected function casts(): array
    {
        return [
            'photographer_type' => PhotographerType::class,
            'status' => PhotographerApplicationStatus::class,
            'services' => 'array',
            'shooting_types' => 'array',
            'additional_document_paths' => 'array',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'can_reapply' => 'boolean',
            'is_freelancer' => 'boolean',
            'is_studio_owner' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function itemReviews(): HasMany
    {
        return $this->hasMany(ApplicationItemReview::class, 'photographer_application_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            PhotographerApplicationStatus::Draft,
            PhotographerApplicationStatus::RevisionRequested,
        ], true);
    }

    /**
     * Whether the non-document business fields (name, location, years,
     * team size, prices, coverage area, shooting types, services) can be
     * updated from the Studio Settings page.
     *
     * Deliberately broader than isEditable(): once an application is
     * Approved, the photographer should still be able to keep their public
     * business details current without re-entering the review flow.
     * Verification documents are NOT covered by this — those still require
     * isEditable() (Draft/RevisionRequested only), so an approved
     * photographer can't silently swap a verification document without
     * admin review.
     */
    public function canUpdateBusinessDetails(): bool
    {
        return $this->isEditable() || $this->status === PhotographerApplicationStatus::Approved;
    }
}