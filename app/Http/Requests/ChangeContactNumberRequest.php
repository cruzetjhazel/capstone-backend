<?php

namespace App\Http\Requests;

use App\Support\PhilippinePhone;
use Illuminate\Foundation\Http\FormRequest;

class ChangeContactNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone_number')) {
            $this->merge(['phone_number' => PhilippinePhone::normalize((string) $this->input('phone_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+639\d{9}$/'],
            'current_password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Enter a valid Philippine mobile number, e.g. 0917 123 4567.',
        ];
    }
}