<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use FreeSms\Support\CodeExtractor;

/**
 * Provider for receive-sms.cc.
 *
 *   Number listing : /US-Phone-Number/
 *   Number page     : /US-Phone-Number/{number}
 *   Message markup  : .row.list-message .item -> .form / .time / .con
 */
final class ReceiveSmsCcProvider extends AbstractProvider
{
    protected const SLUG = 'receive-sms.cc';
    protected const NAME = 'Receive SMS CC';

    protected string $baseUrl = 'https://receive-sms.cc';

    public function getUsNumbers(int $limit = 30): array
    {
        $html  = $this->http->getHtml($this->baseUrl . '/US-Phone-Number/', $this->baseUrl . '/');
        $xpath = $this->xpath($html);
        $cards = $xpath->query(
            '//a[' . self::hasClass('card') . " and contains(@href, '/US-Phone-Number/')]"
        );

        $numbers = [];
        $seen    = [];

        foreach ($cards as $card) {
            $href = $card->getAttribute('href');
            if (!preg_match('#/US-Phone-Number/(\d+)#', $href, $m)) {
                continue;
            }

            $number = $m[1];
            if (isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;

            $title = $xpath->query('.//h4', $card)->item(0);
            $count = $xpath->query('.//*[' . self::hasClass('number') . ']', $card)->item(0);
            $time  = $xpath->query('.//*[' . self::hasClass('time') . ']', $card)->item(0);

            $numbers[] = [
                'source'    => self::SLUG,
                'number'    => $number,
                'display'   => $title ? self::clean($title->textContent) : '+1 ' . $number,
                'link'      => $this->baseUrl . $href,
                'messages'  => $count ? (int) self::clean($count->textContent) : null,
                'last_seen' => $time ? self::clean($time->textContent) : null,
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
        $html   = $this->http->getHtml($this->baseUrl . '/US-Phone-Number/' . $number);
        $xpath  = $this->xpath($html);
        $items  = $xpath->query(
            '//div[' . self::hasClass('list-message') . ']//div[' . self::hasClass('item') . ']'
        );

        $messages = [];

        foreach ($items as $item) {
            $con = $xpath->query('.//*[' . self::hasClass('con') . ']', $item)->item(0);
            if (!$con) {
                continue;
            }

            $from = $xpath->query('.//*[' . self::hasClass('form') . ']', $item)->item(0);
            $time = $xpath->query('.//*[' . self::hasClass('time') . ']', $item)->item(0);
            $text = self::clean($con->textContent);

            $messages[] = [
                'source' => self::SLUG,
                'from'   => $from
                    ? preg_replace('/^From\s+/i', '', self::clean($from->textContent))
                    : '',
                'time'   => $time ? self::clean($time->textContent) : '',
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
