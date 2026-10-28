<?php

namespace App\Http\Controllers\Api\Photographer;

use App\Actions\Report\SubmitReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReplyReviewRequest;
use App\Http\Requests\ReportReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Traits\ApiResponses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $reviews = Review::where('photographer_id', auth()->id())
            ->with(['booking', 'client', 'photographer'])
            ->latest()
            ->get();

        return $this->success(ReviewResource::collection($reviews));
    }

    public function reply(ReplyReviewRequest $request, Review $review)
    {
        $this->authorize('reply', $review);

        $review->update([
            'reply' => $request->validated('reply'),
            'replied_at' => now(),
        ]);

        $review->load(['booking', 'client', 'photographer']);

        return $this->success(new ReviewResource($review), 'Reply published.');
    }

    public function report(ReportReviewRequest $request, Review $review, SubmitReportAction $submitReport)
    {
        $this->authorize('report', $review);

        $reason = $request->validated('reason');
        $review->loadMissing(['client']);

        DB::transaction(function () use ($review, $reason, $request, $submitReport) {
            // File it as a real report so it shows up in Admin > Reports (action: Remove Review).
            // Skipped if this review was already reported, so the admin never gets duplicates.
            if ($review->reported_at === null) {
                $submitReport->execute($request->user(), [
                    'target_type' => 'other',
                    'reference_id' => "Review #{$review->id}",
                    'reason' => Str::limit("Review reported by the photographer: {$reason}", 255, ''),
                    'severity' => 'medium',
                    'details' => Str::limit(
                        "Review #{$review->id} on booking #{$review->booking_id} by ".($review->client?->name ?? 'a client')
                        ." (rating {$review->rating}).\nReason given by the photographer: {$reason}",
                        2000,
                        ''
                    ),
                    'requested_action' => 'remove_review',
                ]);
            }

            $review->update([
                'report_reason' => $reason,
                'reported_at' => now(),
            ]);
        });

        $review->load(['booking', 'client', 'photographer']);

        return $this->success(new ReviewResource($review), 'Review reported to administrators.');
    }
}