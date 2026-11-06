<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\ReportRequestedAction;
use App\Enums\ReportSeverity;
use App\Enums\ReportTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A Client reports on a Studio/Professional; a Photographer reports on
        // a Client. Neither can target their own account type. Matches the
        // dynamic targetOptions split in ReportProblem.tsx.
        $isPhotographer = $this->user()->account_type === AccountType::Photographer;

        $allowedTargets = $isPhotographer
            ? [ReportTargetType::Client, ReportTargetType::Booking, ReportTargetType::Payment, ReportTargetType::Bug, ReportTargetType::Other]
            : [ReportTargetType::Studio, ReportTargetType::Booking, ReportTargetType::Payment, ReportTargetType::Bug, ReportTargetType::Other];

        return [
            'target_type' => ['required', Rule::enum(ReportTargetType::class), Rule::in($allowedTargets)],
            // Required (and must be a real booking of this reporter's) when
            // reporting against a booking — e.g. a no-show report — so an
            // admin reviewing it always has an actual Booking to open rather
            // than a free-text ID that may not even exist. Not required for
            // other target types, which don't necessarily have one.
            'reference_id' => [
                'nullable', 'string', 'max:100',
                Rule::requiredIf(fn () => $this->input('target_type') === ReportTargetType::Booking->value
                    || (! $isPhotographer && $this->input('target_type') === ReportTargetType::Studio->value)),
            ],
            'reason' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(ReportSeverity::class)],
            'details' => ['required', 'string', 'max:2000'],
            'requested_action' => ['required', Rule::enum(ReportRequestedAction::class)],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

        protected function prepareForValidation(): void
    {
        // Bug / "other" reports never carry a record.
        if (in_array($this->input('target_type'), [ReportTargetType::Bug->value, ReportTargetType::Other->value], true)) {
            $this->merge(['reference_id' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'reference_id.required' => 'Please select the record you are reporting.',
            'evidence.max' => 'You can attach up to 3 files.',
            'evidence.*.mimes' => 'Attachments must be PNG, JPG, JPEG or PDF files.',
            'evidence.*.max' => 'Each attachment must be 2 MB or smaller.',
            'evidence.*.uploaded' => 'An attachment could not be uploaded. Make sure each file is 2 MB or smaller.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('reference_id')) {
                return;
            }

            $type = $this->input('target_type');
            $reference = (string) $this->input('reference_id');
            $user = $this->user();
            $isPhotographer = $user->account_type === AccountType::Photographer;

            if ($type === ReportTargetType::Booking->value) {
                $ownerColumn = $isPhotographer ? 'photographer_id' : 'client_id';
                $owns = ctype_digit($reference)
                    && \App\Models\Booking::where('id', $reference)->where($ownerColumn, $user->id)->exists();

                if (! $owns) {
                    $validator->errors()->add('reference_id', 'This booking does not belong to you.');
                }

                return;
            }

            // Photographers keep their existing free-text references.
            if ($isPhotographer) {
                return;
            }

            if ($type === ReportTargetType::Payment->value) {
                $owns = ctype_digit($reference)
                    && \App\Models\Payment::whereKey($reference)
                        ->whereHas('booking', fn ($q) => $q->where('client_id', $user->id))
                        ->exists();

                if (! $owns) {
                    $validator->errors()->add('reference_id', 'This payment does not belong to you.');
                }
            }

            if ($type === ReportTargetType::Studio->value) {
                $exists = ctype_digit($reference)
                    && \App\Models\User::whereKey($reference)
                        ->where('account_type', AccountType::Photographer)
                        ->whereHas('photographerApplication', fn ($q) =>
                            $q->where('status', \App\Enums\PhotographerApplicationStatus::Approved)
                        )
                        ->exists();

                if (! $exists) {
                    $validator->errors()->add('reference_id', 'Please select a registered photographer or studio.');
                }
            }
        });
    }
}