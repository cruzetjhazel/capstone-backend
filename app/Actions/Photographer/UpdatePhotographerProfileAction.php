<?php

namespace App\Actions\Photographer;

use App\Models\PhotographerProfile;
use App\Services\Photographer\CoverPhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UpdatePhotographerProfileAction
{
    public function __construct(protected CoverPhotoProcessor $coverProcessor)
    {
    }

    public function execute(PhotographerProfile $profile, array $data, array $files = []): PhotographerProfile
    {
        $fields = collect($data)->only(['bio', 'style', 'facebook', 'instagram', 'website'])->toArray();

        if (($files['profile_photo'] ?? null) instanceof UploadedFile) {
            $this->deleteIfExists($profile->profile_photo_path);
            $fields['profile_photo_path'] = $this->store($profile, $files['profile_photo'], 'profile');
        }

        if (($files['cover_photo'] ?? null) instanceof UploadedFile) {
            // Process first: if it fails, the old cover is left untouched.
            $newPath = $this->coverProcessor->store($files['cover_photo'], "photographers/{$profile->user_id}/cover");
            $this->deleteIfExists($profile->cover_photo_path);
            $fields['cover_photo_path'] = $newPath;
        }

        $profile->fill($fields)->save();

        return $profile->fresh();
    }

    protected function store(PhotographerProfile $profile, UploadedFile $file, string $type): string
    {
        return $file->store("photographers/{$profile->user_id}/{$type}", 'public');
    }

    protected function deleteIfExists(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}