<?php

namespace App\Http\Controllers\Api\Photographer;


use App\Actions\Client\DeactivateAccountAction;
use App\Actions\Photographer\ChangePasswordAction;
use App\Actions\Photographer\CreatePhotographerProfileAction;
use App\Actions\Photographer\UpdatePhotographerProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureEmailVerifiedForAction;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\DeactivateAccountRequest;
use App\Http\Requests\PhotographerProfileRequest;
use App\Http\Resources\PhotographerProfileResource;
use App\Models\PhotographerProfile;
use App\Services\Photographer\ProfileCompletenessService;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProfileController extends Controller
{
    use ApiResponses;

    public function store(PhotographerProfileRequest $request, CreatePhotographerProfileAction $action)
    {
        $this->authorize('create', PhotographerProfile::class);

        $profile = $action->execute($request->user(), $request->validated(), [
            'profile_photo' => $request->file('profile_photo'),
            'cover_photo' => $request->file('cover_photo'),
        ]);

        return $this->success(new PhotographerProfileResource($profile), 'Profile created.', 201);
    }

    public function show(Request $request)
    {
        $profile = $this->profileFor($request);
        $this->authorize('view', $profile);

        return $this->success(new PhotographerProfileResource($profile));
    }

    public function update(PhotographerProfileRequest $request, UpdatePhotographerProfileAction $action)
    {
        $profile = $this->profileFor($request);
        $this->authorize('update', $profile);

        $profile = $action->execute($profile, $request->validated(), [
            'profile_photo' => $request->file('profile_photo'),
            'cover_photo' => $request->file('cover_photo'),
        ]);

        return $this->success(new PhotographerProfileResource($profile), 'Profile updated.');
    }

    public function completeness(Request $request, ProfileCompletenessService $service)
    {
        if (! $request->user()->isPhotographer()) {
            abort(403, 'Only Photographer accounts have a completeness checklist.');
        }

        return $this->success($service->evaluate($request->user()));
    }

    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $action)
    {
        $action->execute($request->user(), $request->validated('current_password'), $request->validated('password'));

        return $this->success(null, 'Password changed successfully.');
    }

    public function deactivate(DeactivateAccountRequest $request, DeactivateAccountAction $action)
    {
        $action->execute($request->user());

        return $this->success(null, 'Your account has been deactivated.');
    }

    protected function profileFor(Request $request): PhotographerProfile
    {
        $profile = $request->user()->photographerProfile;

        if (! $profile) {
            throw new NotFoundHttpException('No profile found. Create one first.');
        }

        return $profile;
    }
}