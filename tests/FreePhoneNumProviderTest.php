<?php

declare(strict_types=1);

use FreeSms\Http\HttpClient;
use FreeSms\Provider\FreePhoneNumProvider;
use FreeSms\SmsAggregator;

final class FreePhoneNumFixtureClient extends HttpClient
{
    public array $requested = [];

    public function __construct(private string $html)
    {
        parent::__construct();
    }

    public function getHtml(string $url, string $referer = ''): string
    {
        $this->requested[] = $url;
        return $this->html;
    }
}

final class FreePhoneNumProviderTest
{
    public function testOnlyTheLiveVerifiedSourceIsRegistered(): void
    {
        assertSame([['slug' => 'freephonenum.com', 'name' => 'FreePhoneNum']], (new SmsAggregator())->sources());
    }

    public function testRemovedSourcesAreUnavailable(): void
    {
        foreach (['receive-sms.cc', 'receivesmsonline.net', 'esimplus.me', 'sms-online.co', 'receive-sms-free.cc'] as $slug) {
            try {
                (new SmsAggregator())->numbers($slug);
                throw new RuntimeException('Expected removed source to be rejected');
            } catch (InvalidArgumentException $e) {
                assertTrue(str_contains($e->getMessage(), 'Unknown source'));
            }
        }
    }

    public function testPublishedSourceExampleMatchesRegistration(): void
    {
        $example = json_decode((string) file_get_contents(__DIR__ . '/../examples/responses/sources.json'), true, 512, JSON_THROW_ON_ERROR);
        assertSame((new SmsAggregator())->sources(), $example['data']);
    }

    public function testListingDeduplicatesNormalizedNumbersAndHasCanonicalLinks(): void
    {
        $html = '<a href="/us/receive-sms/2025550123">(202) 555-0123 SMS today: 0</a>'
            . '<a href="https://freephonenum.com/us/receive-sms/12025550123/">Duplicate</a>'
            . '<a href="/us/receive-sms/2025550124">Second</a>';
        $provider = new FreePhoneNumProvider(new FreePhoneNumFixtureClient($html));
        $numbers = $provider->getUsNumbers();
        assertSame(2, count($numbers));
        assertSame('12025550123', $numbers[0]['number']);
        assertSame('(202) 555-0123', $numbers[0]['display']);
        assertSame('https://freephonenum.com/us/receive-sms/2025550123', $numbers[0]['link']);
        assertSame(1, count($provider->getUsNumbers(1)));
    }

    public function testListingRejectsForeignMalformedAndOffOriginLinks(): void
    {
        $html = '';
        foreach ([
            'https://evil.test/us/receive-sms/2025550123', '/ca/receive-sms/2025550123',
            '/us/receive-sms/2025550123/extra', '/us/receive-sms/123',
            '/us/receive-sms/2025550123?redirect=elsewhere', '/us/receive-sms/442025550123',
        ] as $href) {
            $html .= '<a href="' . $href . '">Invalid</a>';
        }
        assertSame([], (new FreePhoneNumProvider(new FreePhoneNumFixtureClient($html)))->getUsNumbers());
    }

    public function testSyntheticInboxParsesSenderTextEntitiesAndCode(): void
    {
        $row = '<div class="msg extra" data-service="Test Service">'
            . '<span class="font-bold">Other</span><a class="mono">***0123</a>'
            . '<p class="js-msgtext">Synthetic verification code: 654321 &amp; sample</p></div>';
        $http = new FreePhoneNumFixtureClient($row . $row . '<div class="msg"><p class="js-msgtext"> </p></div>');
        $provider = new FreePhoneNumProvider($http);
        $messages = $provider->getMessages('+1 (202) 555-0123', 1);
        assertSame(1, count($messages));
        assertSame('Test Service (***0123)', $messages[0]['from']);
        assertSame('Synthetic verification code: 654321 & sample', $messages[0]['text']);
        assertSame('654321', $messages[0]['code']);
        assertSame('', $messages[0]['time']);
        assertSame('https://freephonenum.com/us/receive-sms/2025550123', $http->requested[0]);
        assertSame(2, count($provider->getMessages('12025550123')));
    }

    public function testEmptyInboxIsNotAParsingSuccessClaim(): void
    {
        $http = new FreePhoneNumFixtureClient('<p>No messages</p>');
        assertSame([], (new FreePhoneNumProvider($http))->getMessages('12025550123'));
    }

    public function testInvalidNumbersAndNonpositiveLimitsDoNotFetch(): void
    {
        $http = new FreePhoneNumFixtureClient('');
        $provider = new FreePhoneNumProvider($http);
        assertSame([], $provider->getUsNumbers(0));
        assertSame([], $provider->getMessages('12025550123', -1));
        try {
            $provider->getMessages('../../etc/passwd');
            throw new RuntimeException('Expected invalid number');
        } catch (InvalidArgumentException) {
            assertSame([], $http->requested);
        }
    }

    public function testParserRestoresLibxmlErrorMode(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            (new FreePhoneNumProvider(new FreePhoneNumFixtureClient('<div>')))->getUsNumbers();
            assertSame(false, libxml_use_internal_errors());
            libxml_use_internal_errors(true);
            (new FreePhoneNumProvider(new FreePhoneNumFixtureClient('<div>')))->getUsNumbers();
            assertSame(true, libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
