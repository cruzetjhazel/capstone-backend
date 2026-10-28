<?php

namespace App\Models;

use App\Enums\BudgetRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingBudgetRequest extends Model
{
    /** Keep in sync with MAX_INCLUSIONS in BudgetResponsePanel.tsx. */
    public const MAX_INCLUSIONS = 15;

    /** How many budget requests a client may send for one booking (including declined ones). */
    public const MAX_REQUESTS_PER_BOOKING = 3;

    /** How long a client has to answer the photographer's terms (never longer than the booking's hold). */
    public const TERMS_VALID_HOURS = 48;

    protected $fillable = [
        'booking_id', 'status', 'budget_amount', 'client_note',
        'proposed_price', 'proposed_total', 'inclusions',
        'photographer_note', 'decline_reason', 'expires_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BudgetRequestStatus::class,
            'budget_amount' => 'decimal:2',
            'proposed_price' => 'decimal:2',
            'proposed_total' => 'decimal:2',
            'inclusions' => 'array',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Terms the client did not answer before expires_at stop being open. Returns true if this call closed them. */
    public function expireIfStale(): bool
    {
        if ($this->status === BudgetRequestStatus::Proposed && $this->expires_at !== null && $this->expires_at->isPast()) {
            $this->update(['status' => BudgetRequestStatus::Expired]);

            return true;
        }

        return false;
    }
}