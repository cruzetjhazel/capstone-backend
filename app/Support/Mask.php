<?php

namespace App\Support;

/** Masks personal data before it goes into activity logs or notification emails. */
final class Mask
{
    public static function phone(string $number): string
    {
        return '**** '.substr($number, -4);
    }

    public static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return ($local !== '' ? $local[0] : '').'***@'.$domain;
    }
}