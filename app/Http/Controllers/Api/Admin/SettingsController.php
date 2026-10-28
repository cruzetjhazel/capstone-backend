<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\ActivityLog\LogActivityAction;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponses;

    private const LABELS = [
        'login_max_attempts' => 'Login attempts limit',
        'login_lockout_seconds' => 'Lockout time (seconds)',
    ];

    /** GET /admin/settings */
    public function show(Request $request)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        return $this->success([
            'settings' => PlatformSetting::values(),
            'defaults' => PlatformSetting::DEFAULTS,
        ]);
    }

    /** PUT /admin/settings */
    public function update(Request $request, LogActivityAction $activityLogger)
    {
        abort_unless($request->user()->isAdministrator(), 403);

        $data = $request->validate([
            'login_max_attempts' => ['required', 'integer', 'min:3', 'max:10'],
            'login_lockout_seconds' => ['required', 'integer', 'min:30', 'max:600'],
        ], [
            'login_max_attempts.min' => 'Login attempts must be at least 3.',
            'login_max_attempts.max' => 'Login attempts can be at most 10.',
            'login_lockout_seconds.min' => 'Lockout time must be at least 30 seconds.',
            'login_lockout_seconds.max' => 'Lockout time can be at most 600 seconds.',
        ]);

        $old = PlatformSetting::values();
        $changes = [];
        $lastRow = null;

        foreach ($data as $key => $value) {
            if ((int) $value !== $old[$key]) {
                $lastRow = PlatformSetting::put($key, (int) $value);
                $changes[] = self::LABELS[$key].": {$old[$key]} to {$value}";
            }
        }

        if ($changes === []) {
            return $this->success(['settings' => $old], 'No changes to save.');
        }

        $activityLogger->execute(
            causer: $request->user(),
            subject: $lastRow,
            action: 'settings.updated',
            description: 'Updated platform settings. '.implode('; ', $changes).'.',
            metadata: ['old' => $old, 'new' => PlatformSetting::values()],
        );

        return $this->success(['settings' => PlatformSetting::values()], 'Platform settings saved.');
    }
}