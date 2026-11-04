<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePhotographerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership + state enforced in the controller/action
    }

    public function rules(): array
    {
        return [
            'is_freelancer' => ['sometimes', 'boolean'],
            'is_studio_owner' => ['sometimes', 'boolean'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'years_active' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
            'team_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'services' => ['sometimes', 'nullable', 'array'],
            'services.*' => ['string', 'max:100'],
            'other_services' => ['sometimes', 'nullable', 'string', 'max:500'],
            'coverage_area' => ['sometimes', 'nullable', Rule::in([
                'bulan_only', 'bulan_nearby', 'anywhere_sorsogon', 'travel_outside_sorsogon', 'travel_outside_bicol',
            ])],
            'shooting_types' => ['sometimes', 'nullable', 'array'],
            'shooting_types.*' => [Rule::in(['indoor', 'outdoor', 'event_coverage', 'drone_aerial', 'hybrid'])],
            'price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'nullable', 'numeric', 'gte:price_min'],
            'government_id' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'selfie_with_id' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'business_permit' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'additional_documents' => ['sometimes', 'nullable', 'array', 'max:5'],
            'additional_documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}