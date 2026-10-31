<?php

declare(strict_types=1);

use FreeSms\Http\HttpClient;
use FreeSms\Provider\SmsOnlineCoProvider;
use FreeSms\Provider\ReceiveSmsFreeProvider;

final class SyntheticHttpClient extends HttpClient
{
    public int $calls = 0;

    public function __construct(private string $html)
    {
        parent::__construct();
    }

    public function getHtml(string $url, string $referer = ''): string
    {
        $this->calls++;
        return $this->html;
    }
}

final class NewProvidersTest
{
    public function testSmsOnlineListingUsesCountryEvidenceAndCanonicalLinks(): void
    {
        $html = '';
        foreach (['us', 'us', 'pr', 'ca', 'gb'] as $country) {
            $html .= '<div class="number-boxes-item extra"><img src="/flags/' . $country . '.svg">'
                . '<a href="https://sms-online.co/receive-free-sms/12025550123">Read</a></div>';
        }
        $html .= '<div class="number-boxes-item"><img src="/flags/us.svg">'
            . '<a href="https://evil.test/receive-free-sms/12025550124">Bad</a></div>';
        $provider = new SmsOnlineCoProvider(new SyntheticHttpClient($html));
        assertSame(1, count($provider->getUsNumbers()));
        assertSame('12025550123', $provider->getUsNumbers()[0]['number']);
        $foreign = str_replace('/flags/us.svg', '/flags/pr.svg', $html);
        assertSame([], (new SmsOnlineCoProvider(new SyntheticHttpClient($foreign)))->getUsNumbers());
    }

    public function testReceiveSmsFreeListingRequiresUsImageAndDeduplicates(): void
    {
        $card = '<a href="/Free-USA-Phone-Number/12025550123/"><img alt="US phone number" src="/flags/us.svg"></a>';
        $html = $card . $card . str_replace('US phone number', 'Canada phone number', $card)
            . str_replace('12025550123', 'invalid', $card);
        $provider = new ReceiveSmsFreeProvider(new SyntheticHttpClient($html));
        assertSame(1, count($provider->getUsNumbers()));
        assertSame('https://receive-sms-free.cc/Free-USA-Phone-Number/12025550123/', $provider->getUsNumbers()[0]['link']);
    }

    public function testSyntheticInboxFieldsAndLimits(): void
    {
        foreach ([
            [SmsOnlineCoProvider::class, 'list-item', 'list-item-title', 'list-item-meta', 'list-item-content'],
            [ReceiveSmsFreeProvider::class, 'sms-item', 'sender-badge', 'time-text', 'sms-content'],
        ] as [$class, $row, $sender, $time, $body]) {
            $html = '<div class="' . $row . '"><span class="' . $sender . '">Test Sender</span>'
                . '<span class="' . $time . '">2 minutes ago</span><p class="' . $body . '">'
                . 'Synthetic test code: 654321 &amp; sample</p></div>';
            $provider = new $class(new SyntheticHttpClient($html . $html . '<div class="' . $row . '"></div>'));
            $messages = $provider->getMessages('2025550123', 1);
            assertSame(1, count($messages));
            assertSame('Test Sender', $messages[0]['from']);
            assertSame('2 minutes ago', $messages[0]['time']);
            assertSame('Synthetic test code: 654321 & sample', $messages[0]['text']);
            assertSame('654321', $messages[0]['code']);
            assertSame(2, count($provider->getMessages('12025550123')));
        }
    }

    public function testInvalidNumbersAndNonpositiveLimitsDoNotFetch(): void
    {
        foreach ([SmsOnlineCoProvider::class, ReceiveSmsFreeProvider::class] as $class) {
            $http = new SyntheticHttpClient('');
            $provider = new $class($http);
            assertSame([], $provider->getUsNumbers(0));
            assertSame([], $provider->getMessages('12025550123', -1));
            try {
                $provider->getMessages('../../etc/passwd');
                throw new RuntimeException('Expected invalid number');
            } catch (InvalidArgumentException) {
                assertSame(0, $http->calls);
            }
        }
    }

    public function testNewSourcesAreRegistered(): void
    {
        $slugs = array_column((new FreeSms\SmsAggregator())->sources(), 'slug');
        assertTrue(in_array('sms-online.co', $slugs, true));
        assertTrue(in_array('receive-sms-free.cc', $slugs, true));
    }

    public function testParserRestoresLibxmlErrorMode(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            (new SmsOnlineCoProvider(new SyntheticHttpClient('<div>')))->getUsNumbers();
            assertSame(false, libxml_use_internal_errors());
            libxml_use_internal_errors(true);
            (new ReceiveSmsFreeProvider(new SyntheticHttpClient('<div>')))->getUsNumbers();
            assertSame(true, libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testEveryProviderSkipsNonpositiveLimits(): void
    {
        foreach ([
            FreeSms\Provider\ReceiveSmsCcProvider::class, FreeSms\Provider\ReceiveSmsOnlineProvider::class,
            FreeSms\Provider\FreePhoneNumProvider::class, FreeSms\Provider\EsimPlusProvider::class,
            SmsOnlineCoProvider::class, ReceiveSmsFreeProvider::class,
        ] as $class) {
            $http = new SyntheticHttpClient('');
            $provider = new $class($http);
            assertSame([], $provider->getUsNumbers(-1));
            assertSame([], $provider->getMessages('12025550123', 0));
            assertSame(0, $http->calls);
        }
    }
}
