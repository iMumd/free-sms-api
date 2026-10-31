<?php

declare(strict_types=1);

namespace FreeSms\Http;

use RuntimeException;

/**
 * Bounded cURL wrapper for public HTML pages and JSON endpoints.
 *
 * It retries transient failures with a linear backoff and keeps a small
 * per-instance response cache so the same URL is not fetched twice within
 * one request lifecycle.
 *
 * Not final: providers take an HttpClient by constructor injection, so a test
 * can pass a subclass that serves a saved fixture instead of doing I/O.
 */
class HttpClient
{
    private const USER_AGENT = 'FreeSmsProviderAudit/1.0 (+https://github.com/iMumd/free-sms-api)';

    /** @var array<string,string> */
    private array $cache = [];

    public function __construct(
        private readonly int $timeout = 20,
        private readonly int $retries = 2,
        private readonly int $maxBytes = 4_194_304,
    ) {
        if ($timeout < 1 || $retries < 0 || $retries > 5 || $maxBytes < 1) {
            throw new \InvalidArgumentException('Invalid HTTP timeout, retry count or response size limit.');
        }
    }

    /**
     * Fetch a URL and return the response body.
     *
     * @throws RuntimeException when every attempt fails.
     */
    public function getHtml(string $url, string $referer = ''): string
    {
        return $this->request($url, $referer, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
    }

    /**
     * Fetch a URL and decode its body as a JSON object/array.
     *
     * @return array<mixed>
     * @throws RuntimeException when every attempt fails or the body is not valid JSON.
     */
    public function getJson(string $url, string $referer = ''): array
    {
        $body = $this->request($url, $referer, [
            'Accept: application/json, */*;q=0.8',
        ]);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON from {$url}: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * @param array<int,string> $headers request-specific headers merged with the common set
     * @throws RuntimeException when every attempt fails.
     */
    private function request(string $url, string $referer, array $headers): string
    {
        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }
        $key = hash('sha256', serialize([$url, $referer, $headers]));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $lastError = 'unknown error';

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $body = '';
            $tooLarge = false;
            $ch = curl_init($url);
            $https = parse_url($url, PHP_URL_SCHEME) === 'https';
            $protocols = defined('CURLOPT_PROTOCOLS_STR')
                ? [CURLOPT_PROTOCOLS_STR => 'http,https', CURLOPT_REDIR_PROTOCOLS_STR => $https ? 'https' : 'http,https']
                : [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP | CURLPROTO_HTTPS];
            curl_setopt_array($ch, $protocols + [
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => array_merge($headers, [
                    'Accept-Language: en-US,en;q=0.7',
                    'Referer: ' . ($referer !== '' ? $referer : $url),
                ]),
            ]);

            $success = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_error($ch);
            $errno  = curl_errno($ch);
            unset($ch);

            if ($tooLarge) {
                throw new RuntimeException("Response exceeds {$this->maxBytes} bytes: {$url}");
            }
            if ($success !== false && $status >= 200 && $status < 300) {
                if (count($this->cache) >= 32) {
                    array_shift($this->cache);
                }
                $this->cache[$key] = $body;
                return $body;
            }

            $lastError = $error !== '' ? $error : "HTTP {$status}";

            $transient = $errno !== 0
                ? in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_PARTIAL_FILE], true)
                : in_array($status, [408, 429, 500, 502, 503, 504], true);
            if (!$transient) {
                break;
            }
            if ($attempt < $this->retries) {
                usleep(600_000 * ($attempt + 1));
            }
        }

        throw new RuntimeException("Failed to fetch {$url}: {$lastError}");
    }
}
