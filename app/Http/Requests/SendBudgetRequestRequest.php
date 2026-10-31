<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendBudgetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked in SendBudgetRequestAction
    }

    public function rules(): array
    {
        return [
            'budget_amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}