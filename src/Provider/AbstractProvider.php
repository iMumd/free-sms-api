<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use FreeSms\Http\HttpClient;

/**
 * Shared base for HTML-scraping providers.
 *
 * Concrete providers declare SLUG / NAME and implement getUsNumbers() and
 * getMessages(); this class provides the HTTP client, DOM/XPath helpers and
 * common normalisation utilities.
 */
abstract class AbstractProvider implements ProviderInterface
{
    protected const SLUG = '';
    protected const NAME = '';

    /** Base origin, e.g. "https://example.com" (no trailing slash). */
    protected string $baseUrl = '';

    public function __construct(protected HttpClient $http = new HttpClient())
    {
    }

    public function slug(): string
    {
        return static::SLUG;
    }

    public function name(): string
    {
        return static::NAME;
    }

    public function getFirstUsNumber(): ?array
    {
        return $this->getUsNumbers(1)[0] ?? null;
    }

    /** Normalise a phone value to digits only, rejecting empty input. */
    protected function normaliseNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            throw new InvalidArgumentException('A numeric phone number is required.');
        }
        return $digits;
    }

    protected function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** XPath predicate that matches one CSS class inside a multi-class attribute. */
    protected static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /** Collapse whitespace and trim. */
    protected static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
