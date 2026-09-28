<?php

declare(strict_types=1);

namespace FreeSms\Support;

/**
 * Extracts a verification code (OTP) from free-text SMS content.
 *
 * The strategy prefers a digit run that sits next to a keyword such as
 * "code" / "verification" / "OTP"; if none is found it falls back to the
 * first standalone 4-8 digit run. Extraction is heuristic and can pick a
 * non-code number in unusual messages.
 */
final class CodeExtractor
{
    public static function fromText(string $text): ?string
    {
        if (preg_match('/(?:code|otp|pin|password)\D{0,15}(\d{4,8})/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/(\d{4,8})\D{0,15}(?:code|is your|verification)/i', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/\b(\d{4,8})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }
}
