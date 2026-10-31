<?php

declare(strict_types=1);

namespace FreeSms;

use InvalidArgumentException;
use FreeSms\Support\PhoneNumber;
use FreeSms\Provider\EsimPlusProvider;
use FreeSms\Provider\FreePhoneNumProvider;
use FreeSms\Provider\ProviderInterface;
use FreeSms\Provider\ReceiveSmsCcProvider;
use FreeSms\Provider\ReceiveSmsOnlineProvider;
use FreeSms\Provider\SmsOnlineCoProvider;
use FreeSms\Provider\ReceiveSmsFreeProvider;

/**
 * Facade that registers every provider and merges their results behind a
 * single interface. Selecting providers by slug is supported; "all" (the
 * default) fans out across every registered provider.
 */
final class SmsAggregator
{
    /**
     * How many numbers random() draws its candidate pool from. Kept separate
     * from the per-call $limit so that a small limit never narrows the pool.
     */
    private const RANDOM_POOL_SIZE = 30;

    /**
     * How many numbers latest() inspects per provider while looking for an
     * inbox that contains a code. Kept small to bound the upstream requests
     * this endpoint can turn into.
     */
    private const LATEST_PROBES = 3;

    /** @var array<string,ProviderInterface> */
    private array $providers;

    /**
     * @param array<int,ProviderInterface>|null $providers custom set (defaults to the built-ins)
     */
    public function __construct(?array $providers = null)
    {
        $providers ??= [
            new ReceiveSmsCcProvider(),
            new ReceiveSmsOnlineProvider(),
            new FreePhoneNumProvider(),
            new EsimPlusProvider(),
            new SmsOnlineCoProvider(),
            new ReceiveSmsFreeProvider(),
        ];

        $this->providers = [];
        foreach ($providers as $provider) {
            $this->providers[$provider->slug()] = $provider;
        }
    }

    /** @return array<int,array{slug:string,name:string}> */
    public function sources(): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            $out[] = ['slug' => $provider->slug(), 'name' => $provider->name()];
        }
        return $out;
    }

    /**
     * List US numbers from the selected providers.
     *
     * @return array{numbers:array<int,array<string,mixed>>, errors:array<string,string>}
     */
    public function numbers(string $source = 'all', int $limit = 30): array
    {
        $numbers = [];
        $errors  = [];

        foreach ($this->resolve($source) as $slug => $provider) {
            try {
                $numbers = array_merge($numbers, $provider->getUsNumbers($limit));
            } catch (\Throwable $e) {
                $errors[$slug] = $e->getMessage();
            }
        }

        return ['numbers' => $numbers, 'errors' => $errors];
    }

    /** First available number across the selected providers, or null. */
    public function firstNumber(string $source = 'all'): ?array
    {
        $failure = null;
        foreach ($this->resolve($source) as $provider) {
            try {
                $number = $provider->getFirstUsNumber();
            } catch (\Throwable $e) {
                $failure = $e;
                continue;
            }
            if ($number !== null) {
                return $number;
            }
        }
        if ($failure !== null) {
            throw new \RuntimeException('No number could be selected; at least one source failed.', 0, $failure);
        }
        return null;
    }

    /**
     * Fetch messages for a number. When $source is "all", providers are tried
     * in order and the first non-empty result wins.
     *
     * @return array{source:?string, number:string, messages:array<int,array<string,mixed>>, errors:array<string,string>}
     */
    public function messages(string $number, string $source = 'all', int $limit = 20): array
    {
        // Reject an undiallable number once, up front, rather than letting
        // every provider spend a request discovering the same 404.
        $digits = PhoneNumber::requireUs($number);
        $errors = [];

        foreach ($this->resolve($source) as $slug => $provider) {
            try {
                $messages = $provider->getMessages($number, $limit);
                if ($messages !== []) {
                    return [
                        'source'   => $slug,
                        'number'   => $digits,
                        'messages' => $messages,
                        'errors'   => $errors,
                    ];
                }
            } catch (\Throwable $e) {
                $errors[$slug] = $e->getMessage();
            }
        }

        return ['source' => null, 'number' => $digits, 'messages' => [], 'errors' => $errors];
    }

    /** Random available number across the selected providers, or null. */
    public function randomNumber(string $source = 'all', int $poolSize = self::RANDOM_POOL_SIZE): ?array
    {
        $result = $this->numbers($source, $poolSize);
        $pool = $result['numbers'];
        if ($pool === []) {
            if ($result['errors'] !== []) {
                throw new \RuntimeException('No number could be selected; at least one source failed.');
            }
            return null;
        }
        return $pool[array_rand($pool)];
    }

    /**
     * Pick a random available number and return its messages together with the
     * distinct verification codes extracted from them.
     *
     * $limit bounds the returned messages and codes only. The number is drawn
     * from a pool of RANDOM_POOL_SIZE candidates, which is deliberately kept
     * independent of $limit: sharing the value would collapse the pool to a
     * single entry for small limits and make the choice deterministic.
     *
     * @return array{number:array<string,mixed>, messages:array<int,array<string,mixed>>, codes:array<int,string>}|null
     */
    public function random(string $source = 'all', int $limit = 20): ?array
    {
        $number = $this->randomNumber($source);
        if ($number === null) {
            return null;
        }

        // An unreadable inbox must not masquerade as a successfully empty one.
        $messages = $this->providers[$number['source']]->getMessages($number['number'], $limit);

        $codes = [];
        foreach ($messages as $message) {
            $code = $message['code'] ?? null;
            if ($code !== null && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return ['number' => $number, 'messages' => $messages, 'codes' => $codes];
    }

    /**
     * Pick a number and return its messages in one call.
     *
     * A number whose messages actually contain a code is preferred, because
     * the point of this endpoint is to hand back a code. Each provider is
     * probed for at most LATEST_PROBES numbers, and the first inbox seen is
     * kept as a fallback so that a call with no code anywhere still returns
     * 200 with an empty inbox rather than a failure.
     *
     * @return array{number:array<string,mixed>, messages:array<int,array<string,mixed>>}|null
     */
    public function latest(string $source = 'all', int $limit = 20): ?array
    {
        $fallback = null;

        foreach ($this->resolve($source) as $provider) {
            try {
                $candidates = $provider->getUsNumbers(self::LATEST_PROBES);
            } catch (\Throwable) {
                // The listing is unreachable; try the next provider.
                continue;
            }

            foreach ($candidates as $number) {
                try {
                    $messages = $provider->getMessages($number['number'], $limit);
                } catch (\Throwable) {
                    // This number is unreadable; try the next one.
                    continue;
                }

                $fallback ??= ['number' => $number, 'messages' => $messages];

                if (self::hasCode($messages)) {
                    return ['number' => $number, 'messages' => $messages];
                }
            }
        }

        return $fallback;
    }

    /** Whether any message carries an extracted verification code. */
    private static function hasCode(array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['code'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,ProviderInterface>
     * @throws InvalidArgumentException on unknown slug
     */
    private function resolve(string $source): array
    {
        if ($source === '' || $source === 'all') {
            return $this->providers;
        }

        if (!isset($this->providers[$source])) {
            throw new InvalidArgumentException(
                "Unknown source '{$source}'. Available: " . implode(', ', array_keys($this->providers))
            );
        }

        return [$source => $this->providers[$source]];
    }
}
