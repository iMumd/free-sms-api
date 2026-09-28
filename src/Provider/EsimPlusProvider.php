<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use FreeSms\Support\CodeExtractor;

/**
 * Provider for esimplus.me.
 *
 *   Number listing : /temporary-numbers (escaped JSON in the page payload)
 *   Number page     : /temporary-numbers/{slug}/{number}
 *   Messages        : /api/sms-receiver/{number}/sms?perPage=N (JSON)
 */
final class EsimPlusProvider extends AbstractProvider
{
    protected const SLUG = 'esimplus.me';
    protected const NAME = 'eSIM Plus';

    protected string $baseUrl = 'https://esimplus.me';

    public function getUsNumbers(int $limit = 30): array
    {
        $html    = $this->http->getHtml($this->baseUrl . '/temporary-numbers', $this->baseUrl . '/');
        $payload = str_replace('\"', '"', $html);

        preg_match_all(
            '/\{"provider":"[^"]*","phoneNumber":"(\d+)","countryCode":"([A-Z]{2})","slug":"([^"]*)"[^{}]*?"friendlyPhoneNumber":"([^"]*)"\}/',
            $payload,
            $matches,
            PREG_SET_ORDER
        );

        $numbers = [];
        $seen    = [];

        foreach ($matches as $m) {
            [, $number, $country, $slug, $friendly] = $m;

            if ($country !== 'US' || isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;

            $numbers[] = [
                'source'    => self::SLUG,
                'number'    => $number,
                'display'   => $friendly !== '' ? $friendly : '+1 ' . $number,
                'link'      => $this->baseUrl . '/temporary-numbers/' . $slug . '/' . $number,
                'messages'  => null,
                'last_seen' => null,
            ];

            if (count($numbers) >= $limit) {
                break;
            }
        }

        return $numbers;
    }

    public function getMessages(string $number, int $limit = 20): array
    {
        $number = $this->normaliseNumber($number);
        $data   = $this->http->getJson(
            $this->baseUrl . '/api/sms-receiver/' . $number . '/sms?perPage=' . $limit,
            $this->baseUrl . '/temporary-numbers'
        );

        $messages = [];

        foreach ($data['data'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $text = self::clean((string) ($item['body'] ?? ''));
            if ($text === '') {
                continue;
            }

            $messages[] = [
                'source' => self::SLUG,
                'from'   => self::clean((string) ($item['friendlyFrom'] ?? $item['from'] ?? '')),
                'time'   => (string) ($item['receivedAt'] ?? ''),
                'text'   => $text,
                'code'   => CodeExtractor::fromText($text),
            ];

            if (count($messages) >= $limit) {
                break;
            }
        }

        return $messages;
    }
}
