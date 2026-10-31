<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use FreeSms\Support\CodeExtractor;

/** Server-rendered public listings and inboxes on sms-online.co. */
final class SmsOnlineCoProvider extends AbstractProvider
{
    protected const SLUG = 'sms-online.co';
    protected const NAME = 'SMS Online';
    protected string $baseUrl = 'https://sms-online.co';

    public function getUsNumbers(int $limit = 30): array
    {
        if ($limit < 1) {
            return [];
        }
        $xpath = $this->xpath($this->http->getHtml($this->baseUrl . '/receive-free-sms'));
        $numbers = [];
        foreach ($xpath->query('//*[' . self::hasClass('number-boxes-item') . ']') as $card) {
            // +1 also covers Canada and Puerto Rico: require the site's US flag.
            if (!$xpath->query('.//img[contains(@src, "/us.svg")]', $card)->length) {
                continue;
            }
            foreach ($xpath->query('.//a[@href]', $card) as $link) {
                if (!preg_match('~^(?:https://sms-online\.co)?/receive-free-sms/(1\d{10})/?$~D', $link->getAttribute('href'), $m)) {
                    continue;
                }
                $number = $m[1];
                $numbers[$number] = [
                    'source' => self::SLUG, 'number' => $number, 'display' => '+' . $number,
                    'link' => $this->numberUrl($number), 'messages' => null, 'last_seen' => null,
                ];
                if (count($numbers) >= $limit) {
                    return array_values($numbers);
                }
            }
        }
        return array_values($numbers);
    }

    public function getMessages(string $number, int $limit = 20): array
    {
        $number = $this->normaliseNumber($number);
        if ($limit < 1) {
            return [];
        }
        $xpath = $this->xpath($this->http->getHtml($this->numberUrl($number)));
        $messages = [];
        foreach ($xpath->query('//*[' . self::hasClass('list-item') . ']') as $row) {
            $text = $this->field($xpath, $row, 'list-item-content');
            if ($text === '') {
                continue;
            }
            $messages[] = [
                'source' => self::SLUG, 'from' => $this->field($xpath, $row, 'list-item-title'),
                'time' => $this->field($xpath, $row, 'list-item-meta'),
                'text' => $text, 'code' => CodeExtractor::fromText($text),
            ];
            if (count($messages) >= $limit) {
                break;
            }
        }
        return $messages;
    }

    private function field(\DOMXPath $xpath, \DOMNode $row, string $class): string
    {
        return self::clean($xpath->query('.//*[' . self::hasClass($class) . ']', $row)->item(0)?->textContent ?? '');
    }

    private function numberUrl(string $number): string
    {
        return $this->baseUrl . '/receive-free-sms/' . $number;
    }
}
