<?php

namespace App\Http\Requests;

use App\Enums\BookingLocationType;
use App\Models\WalkInClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateManualBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ! $user->isClient() && ! $user->isAdministrator();
    }

    public function rules(): array
    {
        return [
            // Either an existing (non-archived) walk-in client of this photographer, or the details of a new one.
            'walk_in_client_id' => [
                'required_without:new_client', 'prohibits:new_client', 'nullable', 'integer',
                Rule::exists(WalkInClient::class, 'id')
                    ->where('photographer_id', $this->user()?->id)
                    ->where('status', '!=', 'archived'),
            ],
            'new_client' => ['required_without:walk_in_client_id', 'nullable', 'array'],
            'new_client.name' => ['required_with:new_client', 'string', 'max:255'],
            'new_client.phone' => ['required_with:new_client', 'string', 'max:30'],
            'new_client.email' => ['nullable', 'email', 'max:255'],
            'new_client.location' => ['nullable', 'string', 'max:255'],
            'new_client.source' => ['required_with:new_client', Rule::in(['facebook', 'messenger', 'phone_call', 'walk_in', 'referral'])],

            'event_type' => ['required', Rule::in([
                'wedding', 'birthday', 'prenup', 'graduation', 'portrait',
                'corporate_event', 'product_photography', 'family_event', 'other',
            ])],
            'custom_event_type' => ['required_if:event_type,other', 'nullable', 'string', 'max:255'],
            'package_name' => ['nullable', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'location_type' => ['required', Rule::enum(BookingLocationType::class)],
            'event_address' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'total_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'amount_paid' => ['nullable', 'numeric', 'min:0', 'lte:total_price'],
            'mark_completed' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('event_date')) {
                return;
            }

            if ($this->boolean('mark_completed') && $this->input('event_date') > now()->toDateString()) {
                $validator->errors()->add('mark_completed', 'Only an event that has already happened can be marked as completed.');
            }
        }];
    }
}