<?php

namespace App\Http\Controllers\Api;

use App\Enums\CustomPackageComponentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicCustomPackageComponentResource;
use App\Http\Resources\PublicCustomPackageConfigResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPhotographerCustomPackageController extends Controller
{
    use ApiResponses;

    public function show(User $user)
    {
        // Same rule as PublicPhotographerController::show() — a suspended
        // photographer must not be bookable via this endpoint either, even
        // if their profile ID/URL is known. Keep these two checks in sync.
        if (
            ! $user->isPhotographer()
            || ! $user->isApprovedPhotographer()
            || $user->account_status !== \App\Enums\AccountStatus::Active
        ) {
            throw new NotFoundHttpException('Photographer not found.');
        }

        $config = $user->customPackageConfig;

        // No config row, or the photographer has it turned off — tell the
        // client there's nothing to build rather than exposing archived/unset data.
        $hasPrice = $config && match ($config->pricing_model) {
            'hourly' => (float) $config->hourly_rate > 0,
            'per_day', 'per_person' => (float) $config->unit_rate > 0,
            default => (float) $config->base_fee > 0,
        };

        if (! $config || ! $config->enabled || ! $hasPrice) {
            return $this->success([
                'config' => ['enabled' => false, 'base_fee' => null],
                'components' => [],
            ]);
        }

        $components = $user->customPackageComponents()->where('status', 'active')->get();

        return $this->success([
            'config' => new PublicCustomPackageConfigResource($config),
            'components' => PublicCustomPackageComponentResource::collection($components),
        ]);
    }
}