<?php

namespace App\Http\Requests;

use App\Models\BookingBudgetRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondBudgetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked in RespondToBudgetRequestAction
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['accept', 'counter', 'decline'])],

            // counter
            'proposed_price' => ['required_if:action,counter', 'nullable', 'numeric', 'gt:0', 'max:99999999.99'],
            'inclusions' => ['required_if:action,counter', 'nullable', 'array', 'min:1', 'max:' . BookingBudgetRequest::MAX_INCLUSIONS],
            'inclusions.*.name' => ['required', 'string', 'max:120'],
            'inclusions.*.details' => ['nullable', 'string', 'max:500'],

            // accept / counter
            'note' => ['nullable', 'string', 'max:1000'],

            // decline
            'reason' => ['required_if:action,decline', 'nullable', 'string', 'max:1000'],
        ];
    }
}