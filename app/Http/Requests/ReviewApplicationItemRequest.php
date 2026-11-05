<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewApplicationItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_type' => ['required', Rule::in(['government_id', 'selfie_with_id', 'business_permit', 'additional_document', 'portfolio_image'])],
            'item_ref' => ['required', 'string', 'max:64'],
            'status' => ['required', Rule::in(['pending', 'reviewed', 'needs_revision'])],
            'feedback' => ['required_if:status,needs_revision', 'nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return ['feedback.required_if' => 'Please explain what needs to be fixed.'];
    }
}