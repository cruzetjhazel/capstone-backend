<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomPackageConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'pricing_model' => ['sometimes', 'nullable', Rule::in(['fixed', 'hourly', 'per_day', 'per_person'])],
            'base_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'buffer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:480'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'outdoor_hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'base_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'min_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'max_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24', 'gte:min_hours'],
            // Per-day / per-person rate, and the agreed coverage per session
            // (scheduling only — never derived from price). Blank = duration
            // is confirmed by the photographer per booking.
            'unit_rate' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'coverage_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            'max_people' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'allows_multiple_sessions' => ['sometimes', 'boolean'],
            'max_sessions' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:15'],
            'base_fee_mode' => ['sometimes', 'nullable', Rule::in(['once', 'per_schedule'])],
        ];
    }
}