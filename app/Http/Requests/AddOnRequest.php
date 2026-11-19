<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }
}