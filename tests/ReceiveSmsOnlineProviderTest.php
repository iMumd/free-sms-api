<?php

/**
 * Parser behaviour of ReceiveSmsOnlineProvider, pinned against real saved
 * pages so the selectors cannot silently rot.
 *
 * The fixtures under tests/fixtures/ are genuine responses from the site with
 * ad scripts stripped. Nothing here touches the network: the provider's fetch()
 * seam is overridden to read them instead.
 */

declare(strict_types=1);

use FreeSms\Http\HttpClient;
use FreeSms\Provider\ReceiveSmsOnlineProvider;
use FreeSms\Support\PhoneNumber;

/**
 * Serves the saved pages instead of the network, so the provider's real
 * parsing code runs unchanged.
 */
final class FixtureHttpClient extends HttpClient
{
    /** @var array<int,string> */
    public array $requested = [];

    public function getHtml(string $url, string $referer = ''): string
    {
        $this->requested[] = $url;

        $fixture = str_contains($url, '/usa.html') ? 'rso-listing' : 'rso-inbox';
        $path = __DIR__ . '/fixtures/' . $fixture . '.html';

        if (!is_file($path)) {
            throw new RuntimeException("Missing fixture {$fixture}.html");
        }

        return (string) file_get_contents($path);
    }
}

final class ReceiveSmsOnlineProviderTest
{
    private ?FixtureHttpClient $http = null;

    private function http(): FixtureHttpClient
    {
        // The runner builds a fresh instance per test method and has no setup
        // hook, so the client is created on first use.
        return $this->http ??= new FixtureHttpClient();
    }

    private function provider(): ReceiveSmsOnlineProvider
    {
        return new ReceiveSmsOnlineProvider($this->http());
    }

    public function testListingYieldsOnlyUsaNumbers(): void
    {
        $numbers = $this->provider()->getUsNumbers();

        assertTrue($numbers !== [], 'the fixture lists numbers');

        foreach ($numbers as $number) {
            assertTrue(
                PhoneNumber::isUs($number['number']),
                'listed number is a US number: ' . $number['number']
            );
        }
    }

    public function testCanadianNumberIsExcludedDespiteMatchingUs(): void
    {
        $numbers = array_column($this->provider()->getUsNumbers(), 'number');

        // 14504894173 is a Quebec number. It is eleven digits beginning with a
        // 1, so every "is this a US number" test accepts it; only the country
        // flag on the row tells it apart. If this ever fails, the provider has
        // started trusting the number instead of the flag.
        assertTrue(
            !in_array('14504894173', $numbers, true),
            'the Canadian +1 number is not offered as a US number'
        );
    }

    public function testNonUsRowsAreExcluded(): void
    {
        $numbers = array_column($this->provider()->getUsNumbers(), 'number');

        // Germany and the UK share the listing page but are not US.
        foreach (['491771789268', '491771789258', '447441906657'] as $foreign) {
            assertTrue(
                !in_array($foreign, $numbers, true),
                "non-US row {$foreign} is excluded"
            );
        }
    }

    public function testListingEntriesHaveTheDocumentedShape(): void
    {
        $first = $this->provider()->getUsNumbers()[0];

        foreach (['source', 'number', 'display', 'link', 'messages', 'last_seen'] as $key) {
            assertTrue(array_key_exists($key, $first), "entry has '{$key}'");
        }

        assertSame('receivesmsonline.net', $first['source']);
        assertTrue(
            str_contains($first['link'], '/receive-sms-online-' . $first['number'] . '.html'),
            'link points at the number page'
        );
        assertTrue(
            (bool) preg_match('/^\+\d{11}$/', $first['display']),
            'display is a +number: ' . $first['display']
        );
    }

    public function testLimitIsHonoured(): void
    {
        assertSame(2, count($this->provider()->getUsNumbers(2)));
    }

    public function testListingRequestsTheUsaPage(): void
    {
        $this->provider()->getUsNumbers();

        assertTrue(
            str_ends_with($this->http()->requested[0], '/usa.html'),
            'the listing page is the one the selectors were written against'
        );
    }

    public function testMessagesAreParsedFromTheInboxPage(): void
    {
        $messages = $this->provider()->getMessages('12532058123');

        assertTrue($messages !== [], 'the fixture has messages');

        foreach ($messages as $message) {
            foreach (['source', 'from', 'time', 'text', 'code'] as $key) {
                assertTrue(array_key_exists($key, $message), "message has '{$key}'");
            }
            assertSame('receivesmsonline.net', $message['source']);
            assertTrue($message['text'] !== '', 'message text is not empty');
        }
    }

    public function testCodesAreExtractedFromRealMessages(): void
    {
        $messages = $this->provider()->getMessages('12532058123');
        $codes    = array_values(array_filter(array_column($messages, 'code')));

        assertTrue(count($codes) > 5, 'a healthy share of messages carry a code');

        // Every code must be a bare digit run: never a phone number fragment
        // and never a leftover entity.
        foreach ($codes as $code) {
            assertTrue(
                (bool) preg_match('/^\d{4,8}$/', $code),
                "code '{$code}' is a bare digit run"
            );
        }
    }

    public function testMessageLimitIsHonoured(): void
    {
        assertSame(3, count($this->provider()->getMessages('12532058123', 3)));
    }

    public function testEntityEncodedBodiesAreDecoded(): void
    {
        // The site escapes twice over, so bodies arrive carrying "&#38;#39;".
        // None of that may survive into the parsed output.
        foreach ($this->provider()->getMessages('12532058123') as $message) {
            assertTrue(
                !str_contains($message['text'], '&#'),
                'no numeric entities left in: ' . substr($message['text'], 0, 60)
            );
        }
    }

    public function testSenderAndTimeComeFromTheLabelledColumns(): void
    {
        $first = $this->provider()->getMessages('12532058123')[0];

        // Senders arrive pre-masked, e.g. "1760209XXXX".
        assertTrue(
            (bool) preg_match('/\d+X+/', $first['from']),
            'sender looks like a masked number: ' . $first['from']
        );
        assertTrue(
            (bool) preg_match('/\d+\s+\w+\s+ago/i', $first['time']),
            'time is a relative stamp: ' . $first['time']
        );
    }

    public function testAnUndiallableNumberIsRejectedBeforeAnyFetch(): void
    {
        try {
            $this->provider()->getMessages('12345');
            throw new RuntimeException('expected a rejection for a 5-digit value');
        } catch (InvalidArgumentException) {
            assertSame([], $this->http()->requested, 'nothing was fetched');
        }
    }
}
