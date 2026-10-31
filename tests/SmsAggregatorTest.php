<?php

/**
 * Behaviour of the aggregator facade, exercised through scripted providers so
 * nothing here touches the network.
 */

declare(strict_types=1);

use FreeSms\SmsAggregator;

final class SmsAggregatorTest
{
    private function withCode(string $code = '123456'): array
    {
        return [message($code, 'Your code is ' . $code)];
    }

    private function withoutCode(): array
    {
        return [message(null, 'Five Guys Your order is in!')];
    }

    public function testSourcesAreListedInRegistrationOrder(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('alpha'))->withNumbers('10000000001'),
            (new FakeProvider('beta'))->withNumbers('10000000002'),
        ]);

        assertSame(['alpha', 'beta'], array_column($sms->sources(), 'slug'));
        assertSame(['Alpha', 'Beta'], array_column($sms->sources(), 'name'));
    }

    public function testUnknownSourceIsAClientError(): void
    {
        $sms = new SmsAggregator([(new FakeProvider('alpha'))->withNumbers('10000000001')]);

        // The front controller maps this to 400, not 502.
        try {
            $sms->numbers('nope');
            throw new RuntimeException('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            assertTrue(
                str_contains($e->getMessage(), "Unknown source 'nope'"),
                'message names the offending slug'
            );
            assertTrue(
                str_contains($e->getMessage(), 'alpha'),
                'message lists the available slugs'
            );
        }
    }

    public function testNumbersCollectProviderErrors(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('good'))->withNumbers('10000000001', '10000000002'),
            (new FakeProvider('bad'))->withFailingListing(),
        ]);

        $result = $sms->numbers();

        assertSame(2, count($result['numbers']));
        assertSame(['bad'], array_keys($result['errors']));
        assertSame('listing unavailable', $result['errors']['bad']);
    }

    public function testMessagesStopAtTheFirstProviderThatAnswers(): void
    {
        $second = (new FakeProvider('second'))
            ->withNumbers('10000000002')
            ->withInbox('10000000002', $this->withCode('999999'));

        $sms = new SmsAggregator([
            (new FakeProvider('first'))
                ->withNumbers('10000000001')
                ->withInbox('10000000001', $this->withCode('111111')),
            $second,
        ]);

        $result = $sms->messages('10000000001');

        assertSame('first', $result['source']);
        assertSame('111111', $result['messages'][0]['code']);
        assertSame([], $second->probed, 'the second provider is never queried');
    }

    public function testMessagesFallsThroughAnEmptyInbox(): void
    {
        // The same number is offered across sources, so both providers are
        // asked about 10000000001.
        $sms = new SmsAggregator([
            (new FakeProvider('empty'))
                ->withNumbers('10000000001')
                ->withInbox('10000000001', []),
            (new FakeProvider('full'))
                ->withNumbers('10000000001')
                ->withInbox('10000000001', $this->withCode('222222')),
        ]);

        $result = $sms->messages('10000000001');

        assertSame('full', $result['source']);
        assertSame('222222', $result['messages'][0]['code']);
    }

    public function testMessagesReportEveryProviderFailure(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('a'))->withNumbers('10000000001')->withFailures(['10000000001' => 'boom a']),
            (new FakeProvider('b'))->withNumbers('10000000001')->withFailures(['10000000001' => 'boom b']),
        ]);

        $result = $sms->messages('10000000001');

        assertSame([], $result['messages']);
        assertSame(null, $result['source']);
        assertSame(['a', 'b'], array_keys($result['errors']));
    }

    public function testFirstNumberSkipsProvidersWithoutAny(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('empty')),
            (new FakeProvider('full'))->withNumbers('10000000007'),
        ]);

        assertSame('10000000007', $sms->firstNumber()['number']);
        assertSame(null, $sms->firstNumber('empty'), 'a source with no numbers yields null');
    }

    public function testRandomReturnsDistinctCodesOnly(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('alpha'))->withNumbers('10000000001')->withInbox('10000000001', [
                message('111111', 'code 111111'),
                message('222222', 'code 222222'),
                message('111111', 'code 111111 again'),
                message(null, 'no code here'),
            ]),
        ]);

        $result = $sms->random('alpha', 10);

        assertSame(['111111', '222222'], $result['codes']);
        assertSame('10000000001', $result['number']['number']);
    }

    public function testRandomReportsAnUnreadableInboxInsteadOfEmptySuccess(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('alpha'))
                ->withNumbers('10000000001')
                ->withFailures(['10000000001' => 'upstream 502']),
        ]);

        try {
            $sms->random('alpha', 5);
            throw new RuntimeException('Expected upstream failure');
        } catch (RuntimeException $e) {
            assertSame('upstream 502', $e->getMessage());
        }
    }

    public function testFirstNumberContinuesAfterAnUpstreamFailure(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('down'))->withFailingListing(),
            (new FakeProvider('up'))->withNumbers('12025550123'),
        ]);
        assertSame('12025550123', $sms->firstNumber()['number']);
        try {
            $sms->firstNumber('down');
            throw new RuntimeException('Expected upstream error');
        } catch (RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'source failed'));
        }
    }

    public function testRandomIsNullWhenNoNumbersExist(): void
    {
        $sms = new SmsAggregator([(new FakeProvider('empty'))]);

        assertSame(null, $sms->random());
    }

    public function testRandomReportsListingFailures(): void
    {
        $sms = new SmsAggregator([(new FakeProvider('down'))->withFailingListing()]);
        try {
            $sms->random();
            throw new RuntimeException('Expected upstream failure');
        } catch (RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'source failed'));
        }
    }

    /** The whole point of latest(): a number that has a code wins. */
    public function testLatestPrefersANumberWithACode(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('alpha'))
                ->withNumbers('10000000001', '10000000002')
                ->withInbox('10000000001', $this->withoutCode())
                ->withInbox('10000000002', $this->withCode('424242')),
        ]);

        $result = $sms->latest('alpha');

        assertSame('10000000002', $result['number']['number']);
        assertSame('424242', $result['messages'][0]['code']);
    }

    /** With no code anywhere, the first inbox is still returned with 200. */
    public function testLatestFallsBackToTheFirstInbox(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('alpha'))
                ->withNumbers('10000000001', '10000000002')
                ->withInbox('10000000001', $this->withoutCode())
                ->withInbox('10000000002', $this->withoutCode()),
        ]);

        $result = $sms->latest('alpha');

        assertSame('10000000001', $result['number']['number']);
        assertSame(null, $result['messages'][0]['code']);
    }

    public function testLatestMovesOnWhenAProviderIsUnreachable(): void
    {
        $sms = new SmsAggregator([
            (new FakeProvider('down'))
                ->withNumbers('10000000001')
                ->withFailures(['10000000001' => 'connection reset']),
            (new FakeProvider('up'))
                ->withNumbers('10000000002')
                ->withInbox('10000000002', $this->withCode('313131')),
        ]);

        $result = $sms->latest();

        assertSame('10000000002', $result['number']['number']);
        assertSame('313131', $result['messages'][0]['code']);
    }

    /** Probing is bounded so the endpoint cannot fan out without limit. */
    public function testLatestProbesAtMostThreeNumbers(): void
    {
        $provider = (new FakeProvider('alpha'))
            ->withNumbers('10000000001', '10000000002', '10000000003', '10000000004', '10000000005');

        foreach (['10000000001', '10000000002', '10000000003', '10000000004', '10000000005'] as $n) {
            $provider = $provider->withInbox($n, $this->withoutCode());
        }

        $sms = new SmsAggregator([$provider]);
        $result = $sms->latest('alpha');

        assertSame('10000000001', $result['number']['number']);
        assertSame(3, count($provider->probed), 'only the first three are read');
    }

    public function testLatestIsNullWhenNothingIsAvailable(): void
    {
        $sms = new SmsAggregator([(new FakeProvider('alpha'))->withFailingListing()]);

        assertSame(null, $sms->latest());
    }
}
