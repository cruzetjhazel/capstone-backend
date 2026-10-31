<?php

namespace App\Support;

/**
 * Philippine mobile number helpers. Same rules as the Register page and
 * src/lib/phone.ts on the frontend: any accepted format is normalized to +639XXXXXXXXX.
 *
 * Existing rows are in mixed formats (Register stores +639…, the admin screen stores
 * whatever was typed, e.g. 09…), so duplicate checks must look at every variant.
 */
final class PhilippinePhone
{
    public static function normalize(?string $raw): string
    {
        $cleaned = preg_replace('/[\s\-()]/', '', trim((string) $raw)) ?? '';

        if (str_starts_with($cleaned, '+63')) {
            return '+63'.preg_replace('/\D/', '', substr($cleaned, 3));
        }
        if (str_starts_with($cleaned, '63')) {
            return '+63'.preg_replace('/\D/', '', substr($cleaned, 2));
        }
        if (str_starts_with($cleaned, '0')) {
            return '+63'.preg_replace('/\D/', '', substr($cleaned, 1));
        }
        if (str_starts_with($cleaned, '9')) {
            return '+63'.preg_replace('/\D/', '', $cleaned);
        }

        return $cleaned;
    }

    public static function isValid(string $canonical): bool
    {
        return (bool) preg_match('/^\+639\d{9}$/', $canonical);
    }

    /** Every way the same number may be stored: +639…, 09…, 639…, 9… */
    public static function variants(string $canonical): array
    {
        $local = substr($canonical, 3); // 9XXXXXXXXX

        return ['+63'.$local, '0'.$local, '63'.$local, $local];
    }

    public static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && $a !== '' && $b !== ''
            && self::normalize($a) === self::normalize($b);
    }
}