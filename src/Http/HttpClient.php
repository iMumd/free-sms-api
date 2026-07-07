<?php

declare(strict_types=1);

namespace FreeSms\Http;

use RuntimeException;

/**
 * Thin cURL wrapper that fetches HTML pages using real browser headers.
 *
 * It retries transient failures with a linear backoff and keeps a small
 * per-instance response cache so the same URL is not fetched twice within
 * one request lifecycle.
 */
final class HttpClient
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

    /** @var array<string,string> */
    private array $cache = [];

    public function __construct(
        private readonly int $timeout = 20,
        private readonly int $retries = 2,
    ) {
    }

    /**
     * Fetch a URL and return the response body.
     *
     * @throws RuntimeException when every attempt fails.
     */
    public function getHtml(string $url, string $referer = ''): string
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        $lastError = 'unknown error';

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.7',
                    'Referer: ' . ($referer !== '' ? $referer : $url),
                    'Sec-Fetch-Mode: navigate',
                    'Upgrade-Insecure-Requests: 1',
                ],
            ]);

            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_error($ch);
            curl_close($ch);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                $this->cache[$url] = $body;
                return $body;
            }

            $lastError = $error !== '' ? $error : "HTTP {$status}";

            if ($attempt < $this->retries) {
                usleep(600_000 * ($attempt + 1));
            }
        }

        throw new RuntimeException("Failed to fetch {$url}: {$lastError}");
    }
}
