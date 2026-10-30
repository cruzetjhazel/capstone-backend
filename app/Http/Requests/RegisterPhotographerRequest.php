<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterPhotographerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already registered.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(8)->numbers()],
            'photographer_type' => ['required', Rule::in(['freelancer', 'studio'])],
            'terms_accepted' => ['required', 'accepted'],
        ];
    }
}