<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\EnsureEmailVerifiedForAction;
use App\Actions\Photographer\ReapplyPhotographerApplicationAction;
use App\Actions\Photographer\SubmitPhotographerApplicationAction;
use App\Actions\Photographer\UpdatePhotographerApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePhotographerApplicationRequest;
use App\Http\Resources\PhotographerApplicationResource;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PhotographerApplicationController extends Controller
{
    use ApiResponses;

    // Maps the public {type} route segment to the model's path column.
    // additional_documents is intentionally excluded — it's an array field
    // and doesn't fit a single-file download route; add a dedicated
    // index/{index} route later if photographers need to view those too.
    private const DOCUMENT_TYPES = [
        'government_id' => 'government_id_path',
        'selfie_with_id' => 'selfie_with_id_path',
        'business_permit' => 'business_permit_path',
    ];

    protected function applicationFor(Request $request)
    {
        $user = $request->user();

        if (! $user->isPhotographer()) {
            abort(403, 'Only Photographer accounts can access their application.');
        }

        $application = $user->photographerApplication;

        if (! $application) {
            throw new NotFoundHttpException('No photographer application found for this account.');
        }

        return $application;
    }

    public function show(Request $request)
    {
        $application = $this->applicationFor($request);
        $this->authorize('view', $application);

        return $this->success(new PhotographerApplicationResource($application));
    }

    public function update(UpdatePhotographerApplicationRequest $request, UpdatePhotographerApplicationAction $action)
    {
        $application = $this->applicationFor($request);
        $this->authorize('update', $application);



        $application = $action->execute($application, $request->validated(), [
            'government_id' => $request->file('government_id'),
            'selfie_with_id' => $request->file('selfie_with_id'),
            'business_permit' => $request->file('business_permit'),
            'additional_documents' => $request->file('additional_documents', []),
        ]);

        return $this->success(new PhotographerApplicationResource($application), 'Application updated.');
    }

    /**
     * Let a photographer view (not replace) a verification document they
     * submitted during registration/reapplication. Read-only by design —
     * editing these still goes through update(), which is gated by
     * isEditable() so an approved photographer can't silently swap a
     * government ID without re-review.
     *
     * NOTE: assumes verification documents are stored on the 'local'
     * (private) disk, same as the admin download endpoint — adjust here if
     * your filesystems.php uses a different disk name for these paths.
     */
    public function downloadDocument(Request $request, string $type)
    {
        $application = $this->applicationFor($request);
        $this->authorize('view', $application);

        abort_unless(array_key_exists($type, self::DOCUMENT_TYPES), 404, 'Unknown document type.');

        $path = $application->{self::DOCUMENT_TYPES[$type]};

        abort_if(empty($path), 404, 'Document not found.');
        abort_unless(Storage::disk('local')->exists($path), 404, 'Document not found.');

        return Storage::disk('local')->response($path);
    }

    /**
     * View one of the additional proof documents the photographer uploaded.
     * {index} is the zero-based position in additional_document_paths.
     * Read-only, same private disk as the other verification documents.
     */
    public function downloadAdditionalDocument(Request $request, int $index)
    {
        $application = $this->applicationFor($request);
        $this->authorize('view', $application);

        $paths = array_values($application->additional_document_paths ?? []);
        $path = $paths[$index] ?? null;

        abort_if(empty($path), 404, 'Document not found.');
        abort_unless(Storage::disk('local')->exists($path), 404, 'Document not found.');

        return Storage::disk('local')->response($path);
    }

    public function submit(Request $request, SubmitPhotographerApplicationAction $action)
    {
        $application = $this->applicationFor($request);
        $this->authorize('submit', $application);

        $application = $action->execute($application);

        return $this->success(new PhotographerApplicationResource($application), 'Application submitted for review.');
    }

    public function reapply(Request $request, ReapplyPhotographerApplicationAction $action)
    {
        $application = $this->applicationFor($request);
        $this->authorize('reapply', $application);

        $application = $action->execute($application);

        return $this->success(new PhotographerApplicationResource($application), 'You may now update and resubmit your application.');
    }
}