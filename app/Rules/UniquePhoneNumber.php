<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number may belong to only one account, whatever the role.
 *
 * Numbers are compared by their last 10 digits, so +639171234567,
 * 09171234567 and "0917 123 4567" all count as the same number no matter
 * how an older row was stored. The users.phone_number column has no unique
 * index, so this is the only thing preventing duplicates.
 */
class UniquePhoneNumber implements ValidationRule
{
    public const MESSAGE = 'Invalid phone number. This number is already registered.';

    public function __construct(private readonly ?int $ignoreUserId = null)
    {
    }

    public static function exists(string $phone, ?int $ignoreUserId = null): bool
    {
        $tail = self::lastTenDigits($phone);

        if ($tail === null) {
            return false;
        }

        return User::query()
            ->whereNotNull('phone_number')
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->whereRaw(
                "SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone_number, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), -10) = ?",
                [$tail]
            )
            ->exists();
    }

    private static function lastTenDigits(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::exists($value, $this->ignoreUserId)) {
            $fail(self::MESSAGE);
        }
    }
}