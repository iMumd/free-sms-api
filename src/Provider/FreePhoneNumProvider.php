<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use DOMElement;
use FreeSms\Support\CodeExtractor;

/**
 * Provider for freephonenum.com.
 *
 *   Number listing : /us
 *   Number page     : /us/receive-sms/{number}
 *   Message markup  : .msg -> .font-bold (sender) / a.mono (masked number) / .js-msgtext (body)
 *
 * The site does not expose a per-message timestamp, so "time" is empty.
 */
final class FreePhoneNumProvider extends AbstractProvider
{
    protected const SLUG = 'freephonenum.com';
    protected const NAME = 'FreePhoneNum';

    protected string $baseUrl = 'https://freephonenum.com';

    public function getUsNumbers(int $limit = 30): array
    {
        $html  = $this->http->getHtml($this->baseUrl . '/us', $this->baseUrl . '/');
        $xpath = $this->xpath($html);
        $links = $xpath->query('//a[contains(@href, "/us/receive-sms/")]');

        $numbers = [];
        $seen    = [];

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!preg_match('#^https?://freephonenum\.com/us/receive-sms/(\d+)#', $href, $m)
                && !preg_match('#^/us/receive-sms/(\d+)#', $href, $m)) {
                continue;
            }

            $number = $m[1];
            if (isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;

            // Anchor text may carry extras ("SMS today: 0"); keep only the phone pattern.
            $label   = self::clean($link->textContent);
            $display = preg_match('/\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', $label, $mm)
                ? trim($mm[0])
                : $this->formatUs($number);

            $numbers[] = [
                'source'    => self::SLUG,
                'number'    => $number,
                'display'   => $display,
                'link'      => $this->baseUrl . '/us/receive-sms/' . $number,
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
        $html   = $this->http->getHtml($this->baseUrl . '/us/receive-sms/' . $number);
        $xpath  = $this->xpath($html);
        $items  = $xpath->query('//div[' . self::hasClass('msg') . ']');

        $messages = [];

        foreach ($items as $item) {
            $body = $xpath->query('.//*[' . self::hasClass('js-msgtext') . ']', $item)->item(0);
            if (!$body) {
                continue;
            }

            $text = self::clean($body->textContent);
            if ($text === '') {
                continue;
            }

            $service = $item instanceof DOMElement ? trim($item->getAttribute('data-service')) : '';
            $nameEl  = $xpath->query('.//*[' . self::hasClass('font-bold') . ']', $item)->item(0);
            $numEl   = $xpath->query('.//a[' . self::hasClass('mono') . ']', $item)->item(0);

            $from = ($service !== '' && strcasecmp($service, 'Other') !== 0)
                ? $service
                : ($nameEl ? self::clean($nameEl->textContent) : '');

            if ($numEl) {
                $masked = self::clean($numEl->textContent);
                $from   = $from !== '' ? "{$from} ({$masked})" : $masked;
            }

            $messages[] = [
                'source' => self::SLUG,
                'from'   => $from,
                'time'   => '',
                'text'   => $text,
                'code'   => CodeExtractor::fromText($text),
            ];

            if (count($messages) >= $limit) {
                break;
            }
        }

        return $messages;
    }

    /** Format 10 raw digits as "(NPA) NXX-XXXX". */
    private function formatUs(string $digits): string
    {
        return strlen($digits) === 10
            ? sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6))
            : $digits;
    }
}
