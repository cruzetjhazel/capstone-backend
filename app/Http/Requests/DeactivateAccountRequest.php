<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeactivateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', Rule::in(['DEACTIVATE'])],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'You must type DEACTIVATE exactly to confirm.',
            'password.required' => 'Enter your password to confirm.',
        ];
    }
}