<?php

namespace App\Actions\Photographer\CustomPackage;

use App\Models\CustomPackageConfig;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateCustomPackageConfigAction
{
    public function execute(User $user, array $data): CustomPackageConfig
    {
        $existing = CustomPackageConfig::where('user_id', $user->id)->first();

        // A key the request omitted keeps its stored value — separate Save
        // buttons (buffer time, on/off) must never wipe out pricing fields.
        $keep = fn (string $key, $default = null) => array_key_exists($key, $data)
            ? $data[$key]
            : ($existing?->{$key} ?? $default);

        $model = $data['pricing_model'] ?? null;

        if (! $model) {
            if (array_key_exists('hourly_rate', $data) || array_key_exists('base_fee', $data)) {
                // Older clients send base_fee / hourly_rate without a pricing
                // model: an hourly rate means hourly, otherwise fixed.
                $model = ($data['hourly_rate'] ?? null) !== null ? 'hourly' : 'fixed';
            } else {
                $model = $existing?->pricing_model
                    ?: ($existing?->hourly_rate !== null ? 'hourly' : 'fixed');
            }
        }

        $isUnit = in_array($model, ['per_day', 'per_person'], true);

        $values = [
            'enabled' => $data['enabled'],
            'pricing_model' => $model,
            'buffer_minutes' => $keep('buffer_minutes', 0),
            'base_fee' => $model === 'fixed' ? $keep('base_fee') : null,
            'base_hours' => null,
            'hourly_rate' => $model === 'hourly' ? $keep('hourly_rate') : null,
            'outdoor_hourly_rate' => $model === 'hourly' ? $keep('outdoor_hourly_rate') : null,
            'min_hours' => $model === 'hourly' ? $keep('min_hours') : null,
            'max_hours' => $model === 'hourly' ? $keep('max_hours') : null,
            'unit_rate' => $isUnit ? $keep('unit_rate') : null,
            'coverage_hours' => $isUnit ? $keep('coverage_hours') : null,
            'max_people' => $model === 'per_person' ? $keep('max_people') : null,
            'allows_multiple_sessions' => $model === 'per_day' && (bool) $keep('allows_multiple_sessions', false),
            'max_sessions' => ($model === 'per_day' && (bool) $keep('allows_multiple_sessions', false)) ? $keep('max_sessions') : null,
            'base_fee_mode' => null,
        ];

        // An enabled custom package must have a price, otherwise clients could book it for free.
        // `$values` already merges stored values, so a toggle-only save keeps working.
        if ($values['enabled']) {
            $rateField = match ($model) {
                'hourly' => 'hourly_rate',
                'per_day', 'per_person' => 'unit_rate',
                default => 'base_fee',
            };

            if ($values[$rateField] === null) {
                throw ValidationException::withMessages([
                    $rateField => ['Set a price before enabling custom packages.'],
                ]);
            }
        }

        return CustomPackageConfig::updateOrCreate(['user_id' => $user->id], $values);
    }
}