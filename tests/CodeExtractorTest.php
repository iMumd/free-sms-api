<?php

/**
 * Regression tests for the OTP heuristic.
 *
 * The positive cases are verbatim messages captured from the three live
 * upstreams; each one is a case where the previous implementation returned the
 * right answer and must keep doing so. The negative cases are the decoys the
 * old bare-digit fallback used to return as if they were codes.
 */

declare(strict_types=1);

use FreeSms\Support\CodeExtractor;

final class CodeExtractorTest
{
    /** @return array<string,array{0:string,1:?string}> */
    private function cases(): array
    {
        return [
            // --- keyword-led, verbatim from receive-sms.cc ---
            'tiktok leading code' => [
                '[TikTok] 146551 is your verification code, valid for 5 minutes. '
                . 'To keep your account safe, never forward this code.fJpzQvK2eu1',
                '146551',
            ],
            'brand between code and keyword' => [
                '8363 is your Chamet verification code. Don\'t share this code with others.',
                '8363',
            ],
            'trailing code mention must not win' => [
                'Amazon: 937280 is your sign-in code. For your security, don\'t share it. '
                . 'Amazon will never contact you to ask for this code.',
                '937280',
            ],
            'account prefix' => [
                'Account: 905168 is your Samsung account verification code.',
                '905168',
            ],

            // --- keyword-led, verbatim from freephonenum.com ---
            'tiktok ads four digits' => [
                '[TikTok Ads] 5494 is your verification code, valid for 5 minutes. '
                . 'To keep your account safe, never forward this code.',
                '5494',
            ],
            'generic labelled code' => ['Your verification code: 1783242', '1783242'],

            // --- keyword-led, verbatim from esimplus.me ---
            'service suffix' => ['628751 is your verification code for EROME.', '628751'],
            'spelled out time' => ['Your one time verification code is 2587', '2587'],
            'confirmation wording' => [
                'Please enter the confirmation code 1075. Thank you - your PaysafeCard team',
                '1075',
            ],
            'otp keyword' => ['Your OTP is 4482', '4482'],

            // --- no English keyword at all: the bare-run stage must still work ---
            'localised message' => [
                '695627- это ваш код подтверждения. Не сообщайте его никому из',
                '695627',
            ],

            // --- decoys the old fallback returned as codes ---
            'currency amount' => [
                'Hi John, you are scheduled for Balance:5430 USDT at Paysera',
                null,
            ],
            'order reference' => ['Order #123456 shipped', null],
            'phone number' => ['Call 555-123-4567 for support', null],
            'no digits' => ['No digits here', null],
            'split sms fragment' => [
                '“Security and login” > “Security alerts” > “Secure my account”.',
                null,
            ],
            'navigation fragment' => [
                'you, open the TikTok app and go to “Settings and privacy” >',
                null,
            ],
            'date only' => ['Appointment on 09/30/2026 at 5:00AM', null],
        ];
    }

    public function testRealMessages(): void
    {
        foreach ($this->cases() as $name => [$message, $expected]) {
            assertSame($expected, CodeExtractor::fromText($message), $name);
        }
    }

    /** A keyword next to an expiry date is still a code. */
    public function testKeywordWinsOverNearbyDate(): void
    {
        assertSame(
            '123456',
            CodeExtractor::fromText('Your code is 123456. Valid until 09/30/2026.')
        );
    }

    /** A keyword next to a price is still a code. */
    public function testKeywordWinsOverNearbyPrice(): void
    {
        assertSame(
            '448821',
            CodeExtractor::fromText('Code 448821 for your $12.99 order')
        );
    }

    public function testEmptyInput(): void
    {
        assertSame(null, CodeExtractor::fromText(''));
        assertSame(null, CodeExtractor::fromText('   '));
    }

    /** Digits glued to letters are part of a handle, not a code. */
    public function testDigitsInsideWordsAreIgnored(): void
    {
        assertSame(null, CodeExtractor::fromText('Write to user12345 for help'));
    }

    /** Three or nine digit runs are outside the accepted range. */
    public function testRunLengthBounds(): void
    {
        assertSame(null, CodeExtractor::fromText('Reference 123'));
        assertSame(null, CodeExtractor::fromText('Reference 123456789'));
    }

    public function testShortestAndLongestAcceptedRuns(): void
    {
        assertSame('1234', CodeExtractor::fromText('Use 1234 to continue'));
        assertSame('12345678', CodeExtractor::fromText('Use 12345678 to continue'));
    }

    /** Typographic punctuation must not defeat the keyword match. */
    public function testTypographicPunctuation(): void
    {
        assertSame(
            '654321',
            CodeExtractor::fromText('Your code is 654321 — do not share it')
        );
        assertSame(
            '112233',
            CodeExtractor::fromText('“Your code is 112233”')
        );
    }
}
