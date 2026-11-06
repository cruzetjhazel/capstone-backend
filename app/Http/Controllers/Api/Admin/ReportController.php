<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ReportSeverity;
use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Report;
use App\Models\ReportNote;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    use ApiResponses;

    private const STATUS_LABELS = [
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    private const SEVERITY_LABELS = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    private const TARGET_TYPE_LABELS = [
        'client' => 'Client',
        'studio' => 'Professionals',
        'booking' => 'Bookings',
        'payment' => 'Payments',
        'bug' => 'Platform issues',
        'other' => 'Others',
    ];

    private const REQUESTED_ACTION_LABELS = [
        'investigate' => 'Investigate User',
        'refund' => 'Refund Request',
        'cancel' => 'Cancel Booking',
        'warn' => 'Warn User',
        'remove_review' => 'Remove Review',
        'other' => 'Other',
    ];

    /**
     * GET /admin/reports
     * Filterable, paginated report/dispute list for the admin reports page.
     * Also returns platform-wide status counts (unaffected by current filters)
     * for summary stat cards.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(array_column(ReportStatus::cases(), 'value'))],
            'severity' => ['sometimes', 'nullable', Rule::in(array_column(ReportSeverity::cases(), 'value'))],
            'target_type' => ['sometimes', 'nullable', Rule::in(array_column(ReportTargetType::cases(), 'value'))],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'nullable', 'integer'],
        ]);

        $query = Report::with(['reporter.photographerApplication', 'notes.admin']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->string('target_type'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $numericId = (int) preg_replace('/\D/', '', $search) ?: null;

            $query->where(function ($q) use ($search, $numericId) {
                $q->whereHas('reporter', fn ($r) => $r->where('name', 'like', "%{$search}%"))
                    ->orWhere('reference_id', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");

                if ($numericId) {
                    $q->orWhere('id', $numericId);
                }
            });
        }

        $reports = $query->latest()->paginate($request->integer('per_page', 10));
        $reports->getCollection()->transform(fn (Report $r) => $this->mapReport($r));

        $statusCounts = Report::query()->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status');

        return $this->success([
            'reports' => $reports,
            'stats' => [
                'total' => (int) $statusCounts->sum(),
                'submitted' => (int) ($statusCounts['submitted'] ?? 0),
                'under_review' => (int) ($statusCounts['under_review'] ?? 0),
                'resolved' => (int) ($statusCounts['resolved'] ?? 0),
                'closed' => (int) ($statusCounts['closed'] ?? 0),
            ],
        ]);
    }

    /**
     * GET /admin/reports/{report}
     */
    public function show(Request $request, Report $report)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $report->load(['reporter.photographerApplication', 'notes.admin']);

        return $this->success($this->mapReport($report));
    }

    /**
     * PATCH /admin/reports/{report}/status
     */
    public function updateStatus(Request $request, Report $report)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate([
            'status' => ['required', Rule::in(array_column(ReportStatus::cases(), 'value'))],
        ]);

        $newStatus = $request->string('status')->toString();

        $report->update([
            'status' => $newStatus,
            'resolved_at' => in_array($newStatus, ['resolved', 'closed'], true)
                ? ($report->resolved_at ?? now())
                : null,
        ]);

        ActivityLog::create([
            'causer_id' => $request->user()->id,
            'subject_type' => Report::class,
            'subject_id' => $report->id,
            'action' => 'report.status_updated',
            'description' => 'Report status changed to '.(self::STATUS_LABELS[$newStatus] ?? $newStatus).' by administrator.',
        ]);

        return $this->success(
            $this->mapReport($report->fresh(['reporter.photographerApplication', 'notes.admin'])),
            'Report status updated.'
        );
    }

    /**
     * POST /admin/reports/{report}/notes
     */
    public function addNote(Request $request, Report $report)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        ReportNote::create([
            'report_id' => $report->id,
            'admin_id' => $request->user()->id,
            'note' => $request->string('note')->toString(),
        ]);

        return $this->success(
            $this->mapReport($report->fresh(['reporter.photographerApplication', 'notes.admin'])),
            'Note added securely.'
        );
    }

    private function mapReport(Report $r): array
    {
        $statusValue = $r->status?->value ?? (string) $r->status;
        $severityValue = $r->severity?->value ?? (string) $r->severity;
        $targetTypeValue = $r->target_type?->value ?? (string) $r->target_type;
        $requestedActionValue = $r->requested_action?->value ?? (string) $r->requested_action;

        return [
            'id' => $r->referenceCode(),
            'raw_id' => $r->id,
            'date' => $r->created_at?->toISOString(),
            'reporterName' => $r->reporter?->name ?? 'Unknown user',
            'reporterRole' => $this->reporterRole($r->reporter),
            'reportType' => self::TARGET_TYPE_LABELS[$targetTypeValue] ?? $targetTypeValue,
            'referenceId' => $r->reference_id ?: 'N/A',
            'referenceLabel' => $r->referenceLabel(),
            'reason' => $r->reason,
            'severity' => $severityValue,
            'severityLabel' => self::SEVERITY_LABELS[$severityValue] ?? $severityValue,
            'details' => $r->details,
            'expectedOutcome' => self::REQUESTED_ACTION_LABELS[$requestedActionValue] ?? $requestedActionValue,
            'status' => $statusValue,
            'attachments' => collect($r->attachments ?? [])->values(),
            'adminNotes' => $r->notes->map(fn (ReportNote $n) => [
                'date' => $n->created_at?->toISOString(),
                'note' => $n->note,
                'author' => $n->admin?->name ?? 'System',
            ])->values(),
        ];
    }

    private function reporterRole(?User $user): string
    {
        if (! $user) {
            return 'Client';
        }

        $accountType = $user->account_type?->value ?? (string) $user->account_type;

        if ($accountType === 'photographer') {
            return $user->photographerApplication?->photographer_type?->value === 'studio'
                ? 'Studio'
                : 'Freelancer';
        }

        return 'Client';
    }
}