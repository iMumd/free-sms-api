<?php

/**
 * A provider whose every method is scripted, so the aggregator can be tested
 * without touching the network.
 */
final class FakeProvider implements FreeSms\Provider\ProviderInterface
{
    /** @var array<int,string> */
    public array $probed = [];

    /**
     * @param array<int,array<string,mixed>> $numbers
     * @param array<string,array<int,array<string,mixed>>> $inboxes keyed by number
     * @param array<string,string> $failures keyed by number; message thrown instead
     */
    public function __construct(
        private string $slug,
        private array $numbers = [],
        private array $inboxes = [],
        private array $failures = [],
        private bool $listingFails = false,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return ucfirst($this->slug);
    }

    public function getUsNumbers(int $limit = 30): array
    {
        if ($this->listingFails) {
            throw new RuntimeException('listing unavailable');
        }

        return array_slice($this->numbers, 0, $limit);
    }

    public function getMessages(string $number, int $limit = 20): array
    {
        $this->probed[] = $number;

        if (isset($this->failures[$number])) {
            throw new RuntimeException($this->failures[$number]);
        }

        return array_slice($this->inboxes[$number] ?? [], 0, $limit);
    }

    public function getFirstUsNumber(): ?array
    {
        return $this->numbers[0] ?? null;
    }

    /** @param array<string,mixed> $overrides */
    private function entry(string $number, array $overrides = []): array
    {
        return $overrides + [
            'source'    => $this->slug,
            'number'    => $number,
            'display'   => '+1 ' . substr($number, 1),
            'link'      => 'https://example.test/' . $number,
            'messages'  => null,
            'last_seen' => null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function withNumbers(string ...$numbers): self
    {
        $clone = clone $this;
        $clone->numbers = array_map(fn (string $n): array => $this->entry($n), $numbers);

        return $clone;
    }

    /** @param array<int,array<string,mixed>> $messages */
    public function withInbox(string $number, array $messages): self
    {
        $clone = clone $this;
        $clone->inboxes[$number] = $messages;

        return $clone;
    }

    /** @param array<string,string> $failures */
    public function withFailures(array $failures): self
    {
        $clone = clone $this;
        $clone->failures = $failures + $this->failures;

        return $clone;
    }

    public function withFailingListing(): self
    {
        $clone = clone $this;
        $clone->listingFails = true;

        return $clone;
    }
}

/** @param array<int,array<string,mixed>> $overrides */
function message(?string $code, string $text = 'body', array $overrides = []): array
{
    return $overrides + [
        'source' => 'fake',
        'from'   => 'Sender',
        'time'   => '1 minute ago',
        'text'   => $text,
        'code'   => $code,
    ];
}
