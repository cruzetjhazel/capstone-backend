<?php

namespace App\Http\Requests;

use App\Rules\UniquePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

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

        // Older clients send one photographer_type instead of the two classification flags.
        if (! $this->has('is_freelancer') && ! $this->has('is_studio_owner') && $this->has('photographer_type')) {
            $this->merge([
                'is_freelancer' => $this->input('photographer_type') === 'freelancer',
                'is_studio_owner' => $this->input('photographer_type') === 'studio',
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
            'phone_number' => ['required', 'string', 'max:20', new UniquePhoneNumber],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'is_freelancer' => ['required', 'boolean'],
            'is_studio_owner' => ['required', 'boolean'],
            'terms_accepted' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['is_freelancer', 'is_studio_owner'])) {
                return;
            }

            if (! $this->boolean('is_freelancer') && ! $this->boolean('is_studio_owner')) {
                $validator->errors()->add('is_freelancer', 'Select at least one: Freelance Photographer or Studio Owner.');
            }
        }];
    }
}