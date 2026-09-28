<?php

/**
 * Number normalisation and the validation added in front of the providers.
 */

declare(strict_types=1);

use FreeSms\Support\PhoneNumber;

final class PhoneNumberTest
{
    public function testLocalFormGainsTheCountryCode(): void
    {
        assertSame('18044064234', PhoneNumber::us('8044064234'));
        assertSame('18044064234', PhoneNumber::us('1 804 406 4234'));
    }

    public function testCanonicalFormIsLeftAlone(): void
    {
        assertSame('18044064234', PhoneNumber::us('18044064234'));
    }

    /** Formatting is free; the digit run is what matters. */
    public function testFormattingIsIgnored(): void
    {
        foreach (['+1 (804) 406-4234', '804.406.4234', ' 804-406-4234 ', '(804)4064234'] as $written) {
            assertSame('18044064234', PhoneNumber::us($written), $written);
        }
    }

    public function testUsLocalStripsTheCountryCode(): void
    {
        assertSame('8044064234', PhoneNumber::usLocal('18044064234'));
        assertSame('8044064234', PhoneNumber::usLocal('8044064234'));
        assertSame('8044064234', PhoneNumber::usLocal('+1 (804) 406-4234'));
    }

    public function testRoundTripIsStable(): void
    {
        assertSame('18044064234', PhoneNumber::us(PhoneNumber::usLocal('18044064234')));
        assertSame('8044064234', PhoneNumber::usLocal(PhoneNumber::us('8044064234')));
    }

    public function testValidLengthsAreAccepted(): void
    {
        assertTrue(PhoneNumber::isUs('8044064234'), 'local form');
        assertTrue(PhoneNumber::isUs('18044064234'), 'canonical form');
        assertTrue(PhoneNumber::isUs('+1 804 406 4234'), 'punctuated');
    }

    /** The gap that let a five digit value reach the upstreams. */
    public function testWrongLengthsAreRejected(): void
    {
        assertTrue(!PhoneNumber::isUs('12345'), 'five digits');
        assertTrue(!PhoneNumber::isUs('804406423'), 'nine digits');
        assertTrue(!PhoneNumber::isUs('1804406423400'), 'thirteen digits');
        assertTrue(!PhoneNumber::isUs(''), 'empty');
        assertTrue(!PhoneNumber::isUs('abc'), 'no digits');
    }

    /** A country code other than 1 cannot be a US number. */
    public function testForeignCountryCodeIsRejected(): void
    {
        assertTrue(!PhoneNumber::isUs('447911123456'), 'UK');
        assertTrue(!PhoneNumber::isUs('+33 6 12 34 56 78'), 'FR');
    }

    public function testRequireUsNormalisesValidInput(): void
    {
        assertSame('18044064234', PhoneNumber::requireUs('(804) 406-4234'));
        assertSame('18044064234', PhoneNumber::requireUs('18044064234'));
    }

    public function testRequireUsThrowsOnUnusableInput(): void
    {
        foreach (['', 'abc', '12345', '447911123456'] as $bad) {
            try {
                PhoneNumber::requireUs($bad);
                throw new RuntimeException('expected InvalidArgumentException for ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                assertTrue(
                    str_contains($e->getMessage(), 'US phone number'),
                    'message explains the requirement'
                );
            }
        }
    }
}
