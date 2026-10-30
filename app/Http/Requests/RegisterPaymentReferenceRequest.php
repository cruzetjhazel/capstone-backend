<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPaymentReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reference_number')) {
            $this->merge([
                'reference_number' => preg_replace('/\s+/', '', (string) $this->input('reference_number')),
            ]);
        }
    }

    public function messages(): array
{
    return [
        'reference_number.regex' => 'The GCash reference number must be exactly '.SubmitPaymentRequest::GCASH_REFERENCE_DIGITS.' digits (numbers only).',
    ];
}

public function rules(): array
    {
        return [
            'reference_number' => ['required', 'string', 'regex:/^\d{'.SubmitPaymentRequest::GCASH_REFERENCE_DIGITS.'}$/'],
            'amount_received' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}