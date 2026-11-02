<?php

namespace App\Http\Requests;

use App\Services\Photographer\CoverPhotoProcessor;
use Illuminate\Foundation\Http\FormRequest;

class PhotographerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'style.max' => 'You can select up to 6 photography style tags.',
        ];
    }

    public function rules(): array
    {
        return [
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'style' => ['sometimes', 'nullable', 'array', 'max:6'], // photography style tags: 6 max
            'style.*' => ['string', 'max:255'],
            'facebook' => ['sometimes', 'nullable', 'url', 'max:255'],
            'instagram' => ['sometimes', 'nullable', 'url', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'profile_photo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            // 3 MB max. No dimension rules: the server resizes and crops to a 4:1 banner.
            'cover_photo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . CoverPhotoProcessor::MAX_KILOBYTES],
        ];
    }
}