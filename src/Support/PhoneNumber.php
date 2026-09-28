<?php

declare(strict_types=1);

namespace FreeSms\Support;

/**
 * Normalises US phone numbers between the two forms found in the wild:
 * canonical digits with the country code ("13412091143") and the local
 * 10-digit form ("8044064234").
 */
final class PhoneNumber
{
    /** Canonical form: digits only, always with the leading "1". */
    public static function us(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return strlen($digits) === 10 ? '1' . $digits : $digits;
    }

    /** Local form: 10 digits, without the country code. */
    public static function usLocal(string $number): string
    {
        $digits = self::us($number);

        return strlen($digits) === 11 && $digits[0] === '1'
            ? substr($digits, 1)
            : $digits;
    }
}
