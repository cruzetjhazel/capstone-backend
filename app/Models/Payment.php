<?php

namespace App\Models;

use App\Enums\PaymentMatchingStatus;
use App\Enums\PaymentPlan;
use App\Enums\PaymentType;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'client_id', 'photographer_id',
        'type', 'method', 'plan', 'amount', 'reference_number', 'payer_name', 'payment_date', 'notes',
        'photographer_payment_reference_id', 'matching_status', 'active_reference_number',
        'verified_by', 'verified_at', 'verification_action', 'verification_notes',
        'refund_status', 'refund_amount', 'refund_notes', 'refunded_by', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'plan' => PaymentPlan::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date:Y-m-d',
            'matching_status' => PaymentMatchingStatus::class,
            'verified_at' => 'datetime',
            'refund_status' => RefundStatus::class,
            'refund_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function matchedReference(): BelongsTo
    {
        return $this->belongsTo(PhotographerPaymentReference::class, 'photographer_payment_reference_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function isAwaitingManualReview(): bool
    {
        return $this->matching_status === PaymentMatchingStatus::NotMatched;
    }
}