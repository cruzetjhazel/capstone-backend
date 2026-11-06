<?php

namespace App\Models;

use App\Enums\ReportRequestedAction;
use App\Enums\ReportSeverity;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'reporter_id',
        'target_type',
        'reference_id',
        'reason',
        'severity',
        'details',
        'requested_action',
        'status',
        'attachments',
        'resolved_at',
    ];

    protected $casts = [
        'target_type' => ReportTargetType::class,
        'severity' => ReportSeverity::class,
        'requested_action' => ReportRequestedAction::class,
        'status' => ReportStatus::class,
        'attachments' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ReportNote::class);
    }

    /**
     * Readable name for the record this report points at (e.g. "Booking BK-0012",
     * a photographer's name, a payment reference). Null when there is nothing to
     * resolve: older typed-in references, or bug/other reports.
     */
    public function referenceLabel(): ?string
    {
        if (! $this->reference_id || ! ctype_digit((string) $this->reference_id)) {
            return null;
        }

        return match ($this->target_type) {
            ReportTargetType::Booking => 'Booking BK-'.str_pad((string) $this->reference_id, 4, '0', STR_PAD_LEFT),
            ReportTargetType::Payment => ($payment = Payment::find($this->reference_id))
                ? 'Payment '.($payment->reference_number ?: '#'.$payment->id)
                : null,
            ReportTargetType::Studio => User::find($this->reference_id)?->name,
            default => null,
        };
    }

    /** Display-friendly reference code, e.g. "RPT-00042". */
    public function referenceCode(): string
    {
        return 'RPT-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}