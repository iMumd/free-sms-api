<?php

declare(strict_types=1);

namespace FreeSms\Support;

use InvalidArgumentException;

/**
 * Normalises US phone numbers between the two forms found in the wild:
 * canonical digits with the country code ("13412091143") and the local
 * 10-digit form ("8044064234").
 */
final class PhoneNumber
{
    /** Digit count of the local form, without the country code. */
    private const LOCAL_LENGTH = 10;

    /** Canonical form: digits only, always with the leading "1". */
    public static function us(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return strlen($digits) === self::LOCAL_LENGTH ? '1' . $digits : $digits;
    }

    /** Local form: 10 digits, without the country code. */
    public static function usLocal(string $number): string
    {
        $digits = self::us($number);

        return strlen($digits) === self::LOCAL_LENGTH + 1 && $digits[0] === '1'
            ? substr($digits, 1)
            : $digits;
    }

    /**
     * Whether a value can be a US number at all, in either accepted form.
     *
     * Formatting is free — "+1 (804) 406-4234", "8044064234" and
     * "18044064234" are all the same number. The length is not: anything
     * other than 10 or 11 digits cannot be dialled, and sending it upstream
     * only spends a request to be told 404.
     */
    public static function isUs(string $number): bool
    {
        $digits = self::us($number);
        $length = strlen($digits);

        if ($length === self::LOCAL_LENGTH + 1) {
            return $digits[0] === '1';
        }

        return $length === self::LOCAL_LENGTH;
    }

    /**
     * Canonical digits for a value that must be a US number.
     *
     * @throws InvalidArgumentException when the value cannot be one
     */
    public static function requireUs(string $number): string
    {
        if (!self::isUs($number)) {
            throw new InvalidArgumentException(
                'A US phone number of 10 digits, optionally with a leading country '
                . 'code, is required.'
            );
        }

        return self::us($number);
    }
}
