<?php

declare(strict_types=1);

namespace FreeSms\Support;

/**
 * Extracts a verification code (OTP) from free-text SMS content.
 *
 * Candidates are gathered in descending order of confidence and the first one
 * that survives a screen is returned:
 *
 *   1. A digit run sitting next to a keyword such as "code" / "OTP" /
 *      "verification", in either order. Providers that bother to say "your
 *      code is 1234" are telling us the truth, so these are only rejected when
 *      the run is structurally part of a longer number.
 *   2. Otherwise the first standalone 4-8 digit run that survives a stricter
 *      screen for what a bare digit run usually is *not*: part of a phone
 *      number, part of a date, a money amount, or an order reference.
 *
 * Returning null is a normal, common outcome. Messages with no code in them
 * outnumber the ones carrying a decoy number, and guessing is worse than
 * abstaining: a caller cannot tell a wrong answer from a right one.
 */
final class CodeExtractor
{
    /**
     * The bridge between a keyword and its digits. Providers phrase it as
     * "your code is 1234", "code: 1234" or "code for 1234", so the gap has to
     * step over a couple of short filler words. It deliberately cannot cross a
     * letter or a digit that is not a listed filler, which keeps "code expires
     * in 5 min" from reaching for an unrelated number further along.
     */
    private const GAP = '(?:[^\p{L}\p{N}]|\b(?:is|was|are|be|been|to|for|at|of|your|the|a|an)\b){0,20}?';

    /**
     * Keywords that introduce a code which follows them. "verification",
     * "confirmation" and "passcode" are included because providers write
     * "verification code" and "confirmation code" far more often than a bare
     * "code", and a localised "pin" appears without one.
     */
    private const KEYWORD_BEFORE_CODE = '/\b(?:code|otp|pin|passcode|password|verification|confirmation)\b'
        . self::GAP
        . '(\d{4,8})\b/i';

    /** Phrases that follow a code which precedes them. */
    private const KEYWORD_AFTER_CODE = '/(\d{4,8})\b'
        . self::GAP
        . '(?:is your|is the|verification|security code|confirmation code|one[- ]time|access code|login code)\b/i';

    /** A bare year is a date far more often than it is a code. */
    private const YEAR = '/^(?:19|20)\d{2}$/';

    /** The run is one group of a phone number such as 555-123-4567. */
    private const PHONE_BEFORE = '/\d[ .\-]$/';
    private const PHONE_AFTER  = '/^[ .\-]\d[ .\-]/';

    /** Money. Applies to bare runs only: a code is often quoted next to one. */
    private const AMOUNT_CONTEXT = '/(?:balance|amount|total|usdt|usd|eur|gbp|cad|aud|inr|price|fee|paid|salary|refund|\$|€|£|₹)/i';

    /** Order and shipment references. */
    private const REFERENCE_CONTEXT = '/(?:order|invoice|tracking|shipped|shipment|customer service|reference|\bref\b)/i';

    /** Dates and clock times. */
    private const DATE_CONTEXT = '/\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b|\b\d{1,2}:\d{2}\b/';

    /** How much surrounding text is inspected for disqualifying context. */
    private const CONTEXT_WINDOW = 32;

    /** How far either side is inspected for adjacent digit groups. */
    private const NEIGHBOUR_WINDOW = 8;

    public static function fromText(string $text): ?string
    {
        $text = self::normalise($text);
        if ($text === '') {
            return null;
        }

        foreach (self::candidates($text) as [$digits, $offset, $keywordLed]) {
            if (self::isPlausibleCode($text, $offset, $digits, $keywordLed)) {
                return $digits;
            }
        }

        return null;
    }

    /**
     * Every digit run worth considering, keyword-led runs first.
     *
     * @return array<int,array{0:string,1:int,2:bool}> [digits, byte offset, keyword-led]
     */
    private static function candidates(string $text): array
    {
        $found = [];

        foreach ([self::KEYWORD_BEFORE_CODE, self::KEYWORD_AFTER_CODE] as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as [$digits, $offset]) {
                    $found[] = [(string) $digits, (int) $offset, true];
                }
            }
        }

        if (preg_match_all('/\b(\d{4,8})\b/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$digits, $offset]) {
                $found[] = [(string) $digits, (int) $offset, false];
            }
        }

        return $found;
    }

    /**
     * @param bool $keywordLed whether a keyword vouched for this run
     */
    private static function isPlausibleCode(string $text, int $offset, string $digits, bool $keywordLed): bool
    {
        if (preg_match(self::YEAR, $digits)) {
            return false;
        }

        $before = substr($text, max(0, $offset - self::NEIGHBOUR_WINDOW), self::NEIGHBOUR_WINDOW);
        $after  = substr($text, $offset + strlen($digits), self::NEIGHBOUR_WINDOW);

        if (preg_match(self::PHONE_BEFORE, $before) || preg_match(self::PHONE_AFTER, $after)) {
            return false;
        }

        if ($keywordLed) {
            // A provider that names the code is authoritative even when an
            // expiry date or a price sits nearby.
            return true;
        }

        $window = substr(
            $text,
            max(0, $offset - self::CONTEXT_WINDOW),
            strlen($digits) + 2 * self::CONTEXT_WINDOW
        );

        return !preg_match(self::AMOUNT_CONTEXT, $window)
            && !preg_match(self::REFERENCE_CONTEXT, $window)
            && !preg_match(self::DATE_CONTEXT, $window);
    }

    /** Collapse whitespace and fold typographic punctuation back to ASCII. */
    private static function normalise(string $text): string
    {
        $text = strtr($text, [
            "\u{00A0}"   => ' ',
            "\u{2018}"   => "'",
            "\u{2019}"   => "'",
            "\u{201C}"   => '"',
            "\u{201D}"   => '"',
            "\u{2013}"   => '-',
            "\u{2014}"   => '-',
            "\u{200B}"   => '',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
