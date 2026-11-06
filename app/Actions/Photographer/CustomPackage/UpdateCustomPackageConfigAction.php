<?php

namespace App\Actions\Photographer\CustomPackage;

use App\Models\CustomPackageConfig;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateCustomPackageConfigAction
{
    /**
     * Request keys that describe pricing. A request carrying none of these is a
     * plain on/off (or buffer-only) save from the Custom Package switch.
     */
    private const PRICING_KEYS = [
        'pricing_model', 'base_fee', 'hourly_rate', 'outdoor_hourly_rate',
        'min_hours', 'max_hours', 'unit_rate', 'coverage_hours',
        'max_people', 'allows_multiple_sessions', 'max_sessions',
    ];

    public function execute(User $user, array $data): CustomPackageConfig
    {
        $existing = CustomPackageConfig::where('user_id', $user->id)->first();

        // A key the request omitted keeps its stored value - separate Save
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

        // Turning the calculator ON is allowed before any price exists: the studio
        // enables it first, then sets rates in the Custom Package tab. Clients
        // must not be able to use it until a price is set (see isPriced()).
        //
        // Saving pricing while enabled still requires a real price, so the
        // stored config can never be "enabled with a free calculator".
        $isToggleOnly = count(array_intersect(self::PRICING_KEYS, array_keys($data))) === 0;

        if ($values['enabled'] && ! $isToggleOnly && ! self::hasPrice($values, $model)) {
            $rateField = self::rateField($model);

            throw ValidationException::withMessages([
                $rateField => ['Set a price greater than 0 for the selected pricing model.'],
            ]);
        }

        return CustomPackageConfig::updateOrCreate(['user_id' => $user->id], $values);
    }

    /** The column holding the main price for a pricing model. */
    public static function rateField(string $model): string
    {
        return match ($model) {
            'hourly' => 'hourly_rate',
            'per_day', 'per_person' => 'unit_rate',
            default => 'base_fee',
        };
    }

    /** True when the active pricing model has a price above zero. */
    public static function hasPrice(array|CustomPackageConfig $config, ?string $model = null): bool
    {
        $get = fn (string $key) => is_array($config) ? ($config[$key] ?? null) : $config->{$key};

        $model ??= $get('pricing_model') ?: ($get('hourly_rate') !== null ? 'hourly' : 'fixed');

        return (float) ($get(self::rateField($model)) ?? 0) > 0;
    }

    /** True when the config is on AND priced, i.e. safe to show clients. */
    public static function isPriced(CustomPackageConfig $config): bool
    {
        return (bool) $config->enabled && self::hasPrice($config);
    }
}