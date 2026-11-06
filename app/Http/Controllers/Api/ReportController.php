<?php

namespace App\Http\Controllers\Api;

use App\Actions\Report\SubmitReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Report::class);

        $reports = Report::with('notes')
            ->where('reporter_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->success(ReportResource::collection($reports));
    }

    public function store(SubmitReportRequest $request, SubmitReportAction $action)
    {
        $report = $action->execute(
            $request->user(),
            $request->validated(),
            $request->file('evidence', [])
        );

        return $this->success(new ReportResource($report), 'Report submitted.', 201);
    }

    /** GET /client/reports/photographers?search= — small list for the Report a Problem picker. */
    public function photographers(Request $request)
    {
        abort_unless($request->user()->isClient(), 403);

        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $photographers = \App\Models\User::query()
            ->where('account_type', \App\Enums\AccountType::Photographer)
            ->whereHas('photographerApplication', fn ($q) =>
                $q->where('status', \App\Enums\PhotographerApplicationStatus::Approved)
            )
            ->when($request->filled('search'), fn ($q) =>
                $q->where('name', 'like', '%'.$request->string('search')->toString().'%')
            )
            ->with(['photographerApplication:id,user_id,photographer_type', 'photographerProfile'])
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->photographerProfile?->profile_photo_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($u->photographerProfile->profile_photo_path)
                    : null,
                'type' => $u->photographerApplication?->photographer_type?->value === 'studio' ? 'Studio' : 'Freelancer',
            ])
            ->values();

        return $this->success($photographers);
    }

    public function show(Request $request, Report $report)
    {
        $this->authorize('view', $report);

        return $this->success(new ReportResource($report->load('notes')));
    }
}