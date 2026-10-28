<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Every editable setting and its default. A missing row simply uses the default. */
    public const DEFAULTS = [
        'login_max_attempts' => 5,      // failed logins per email before a lockout
        'login_lockout_seconds' => 60,  // how long the lockout lasts
    ];

    public static function values(): array
    {
        // If the table is missing or unreadable, fall back to the defaults so login never breaks.
        try {
            $stored = Cache::remember('platform_settings', 3600, fn () => static::query()->pluck('value', 'key')->all());
        } catch (\Throwable $e) {
            report($e);
            $stored = [];
        }

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = isset($stored[$key]) ? (int) $stored[$key] : $default;
        }

        return $out;
    }

    public static function get(string $key): int
    {
        return self::values()[$key] ?? self::DEFAULTS[$key];
    }

    public static function put(string $key, int $value): self
    {
        $row = static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget('platform_settings');

        return $row;
    }
}