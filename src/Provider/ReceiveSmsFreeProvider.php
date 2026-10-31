<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use FreeSms\Support\CodeExtractor;

/** Server-rendered public US cards and SMS rows on receive-sms-free.cc. */
final class ReceiveSmsFreeProvider extends AbstractProvider
{
    protected const SLUG = 'receive-sms-free.cc';
    protected const NAME = 'Receive SMS Free';
    protected string $baseUrl = 'https://receive-sms-free.cc';

    public function getUsNumbers(int $limit = 30): array
    {
        if ($limit < 1) {
            return [];
        }
        $xpath = $this->xpath($this->http->getHtml($this->baseUrl . '/Free-USA-Phone-Number/'));
        $numbers = [];
        foreach ($xpath->query('//a[@href][.//img[@alt="US phone number"]]') as $link) {
            if (!preg_match('~^(?:https://receive-sms-free\.cc)?/Free-USA-Phone-Number/(1\d{10})/?$~D', $link->getAttribute('href'), $m)) {
                continue;
            }
            $number = $m[1];
            $numbers[$number] = [
                'source' => self::SLUG, 'number' => $number, 'display' => '+' . $number,
                'link' => $this->numberUrl($number), 'messages' => null, 'last_seen' => null,
            ];
            if (count($numbers) >= $limit) {
                break;
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
        foreach ($xpath->query('//*[' . self::hasClass('sms-item') . ']') as $row) {
            $text = $this->field($xpath, $row, 'sms-content');
            if ($text === '') {
                continue;
            }
            $messages[] = [
                'source' => self::SLUG, 'from' => $this->field($xpath, $row, 'sender-badge'),
                'time' => $this->field($xpath, $row, 'time-text'),
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
        return $this->baseUrl . '/Free-USA-Phone-Number/' . $number . '/';
    }
}
