<?php

namespace App\Actions\Photographer;

use App\Models\PhotographerProfile;
use App\Models\User;
use App\Services\Photographer\CoverPhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CreatePhotographerProfileAction
{
    public function __construct(protected CoverPhotoProcessor $coverProcessor)
    {
    }

    public function execute(User $user, array $data, array $files = []): PhotographerProfile
    {
        if ($user->photographerProfile()->exists()) {
            throw ValidationException::withMessages([
                'profile' => ['A profile already exists for this account.'],
            ]);
        }

        $profile = PhotographerProfile::create([
            'user_id' => $user->id,
            'bio' => $data['bio'] ?? null,
            'style' => $data['style'] ?? null,
            'facebook' => $data['facebook'] ?? null,
            'instagram' => $data['instagram'] ?? null,
            'website' => $data['website'] ?? null,
        ]);

        $fields = [];

        if (($files['profile_photo'] ?? null) instanceof UploadedFile) {
            $fields['profile_photo_path'] = $this->store($profile, $files['profile_photo'], 'profile');
        }

        if (($files['cover_photo'] ?? null) instanceof UploadedFile) {
            $fields['cover_photo_path'] = $this->coverProcessor->store($files['cover_photo'], "photographers/{$profile->user_id}/cover");
        }

        if ($fields) {
            $profile->update($fields);
        }

        return $profile->fresh();
    }

    protected function store(PhotographerProfile $profile, UploadedFile $file, string $type): string
    {
        return $file->store("photographers/{$profile->user_id}/{$type}", 'public');
    }
}