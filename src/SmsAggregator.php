<?php

declare(strict_types=1);

namespace FreeSms;

use RuntimeException;
use FreeSms\Provider\EsimPlusProvider;
use FreeSms\Provider\FreePhoneNumProvider;
use FreeSms\Provider\ProviderInterface;
use FreeSms\Provider\ReceiveSmsCcProvider;

/**
 * Facade that registers every provider and merges their results behind a
 * single interface. Selecting providers by slug is supported; "all" (the
 * default) fans out across every registered provider.
 */
final class SmsAggregator
{
    /** @var array<string,ProviderInterface> */
    private array $providers;

    /**
     * @param array<int,ProviderInterface>|null $providers custom set (defaults to the built-ins)
     */
    public function __construct(?array $providers = null)
    {
        $providers ??= [
            new ReceiveSmsCcProvider(),
            new FreePhoneNumProvider(),
            new EsimPlusProvider(),
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
        foreach ($this->resolve($source) as $provider) {
            $number = $provider->getFirstUsNumber();
            if ($number !== null) {
                return $number;
            }
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
        $digits = preg_replace('/\D+/', '', $number) ?? '';
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
    public function randomNumber(string $source = 'all', int $limit = 30): ?array
    {
        $pool = $this->numbers($source, $limit)['numbers'];
        if ($pool === []) {
            return null;
        }
        return $pool[array_rand($pool)];
    }

    /**
     * Pick a random available number and return its messages together with the
     * distinct verification codes extracted from them.
     *
     * @return array{number:array<string,mixed>, messages:array<int,array<string,mixed>>, codes:array<int,string>}|null
     */
    public function random(string $source = 'all', int $limit = 20): ?array
    {
        $number = $this->randomNumber($source);
        if ($number === null) {
            return null;
        }

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
     * Pick the first available number and return its messages in one call.
     *
     * @return array{number:array<string,mixed>, messages:array<int,array<string,mixed>>}|null
     */
    public function latest(string $source = 'all', int $limit = 20): ?array
    {
        foreach ($this->resolve($source) as $provider) {
            try {
                $number = $provider->getFirstUsNumber();
                if ($number === null) {
                    continue;
                }
                return [
                    'number'   => $number,
                    'messages' => $provider->getMessages($number['number'], $limit),
                ];
            } catch (\Throwable) {
                // try the next provider
            }
        }
        return null;
    }

    /**
     * @return array<string,ProviderInterface>
     * @throws RuntimeException on unknown slug
     */
    private function resolve(string $source): array
    {
        if ($source === '' || $source === 'all') {
            return $this->providers;
        }

        if (!isset($this->providers[$source])) {
            throw new RuntimeException(
                "Unknown source '{$source}'. Available: " . implode(', ', array_keys($this->providers))
            );
        }

        return [$source => $this->providers[$source]];
    }
}
