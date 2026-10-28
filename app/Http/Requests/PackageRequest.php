<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'included_items' => ['sometimes', 'nullable', 'array', 'max:15'],
            'included_items.*.name' => ['required', 'string', 'max:255'],
            'included_items.*.detail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'gt:0'],
            // Optional different price when the booking is NOT at the studio.
            'outdoor_price' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            // Nullable: a fixed package can now be "duration TBD" — e.g. a
            // multi-schedule package (prenup/prep/ceremony/reception) whose
            // individual schedule lengths aren't fixed in advance. See
            // CreateBookingAction, which stops treating this as a hard
            // requirement and instead lets each booked schedule carry (or
            // omit) its own duration.
            'schedule_mode' => ['sometimes', Rule::in(['timed', 'open'])],
            // Required when creating a timed package; optional (informational) for open-ended ones.
            'duration_minutes' => [
                Rule::requiredIf(fn () => $this->isMethod('post') && $this->input('schedule_mode', 'timed') === 'timed'),
                'nullable', 'integer', 'min:1',
            ],
            'buffer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'allows_multiple_sessions' => ['sometimes', 'boolean'],
            // Total sessions per booking, including the first. Null = no cap.
            'max_sessions' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:15'],
        ];
    }
}