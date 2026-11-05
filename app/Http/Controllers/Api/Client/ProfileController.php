<?php

namespace App\Http\Controllers\Api\Client;

use App\Actions\Client\ChangePasswordAction;
use App\Actions\Client\DeactivateAccountAction;
use App\Actions\Client\UpdateClientProfileAction;
use App\Http\Middleware\EnsureEmailVerifiedForAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\DeactivateAccountRequest;
use App\Http\Requests\UpdateClientProfileRequest;
use App\Http\Resources\ClientProfileResource;
use App\Models\ClientProfile;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponses;

    public function show(Request $request)
    {
        $profile = $request->user()->clientProfile ?? new ClientProfile(['user_id' => $request->user()->id]);
        $profile->setRelation('user', $request->user());

        return $this->success(new ClientProfileResource($profile));
    }

    public function update(UpdateClientProfileRequest $request, UpdateClientProfileAction $action)
    {
        if (! $request->user()->hasVerifiedEmail() && $request->hasFile('profile_photo')) {
           return EnsureEmailVerifiedForAction::deny();
        }
        $profile = $action->execute($request->user(), $request->validated(), $request->file('profile_photo'));
        $profile->setRelation('user', $request->user()->fresh());

        return $this->success(new ClientProfileResource($profile), 'Profile updated.');
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
}
