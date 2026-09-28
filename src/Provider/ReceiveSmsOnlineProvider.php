<?php

declare(strict_types=1);

namespace FreeSms\Provider;

use FreeSms\Support\CodeExtractor;
use FreeSms\Support\PhoneNumber;

/**
 * Provider for receivesmsonline.net.
 *
 *   Number listing : /usa.html
 *   Number page     : /receive-sms-online-{number}.html
 *   Message markup  : table tr -> td[@data-title='From Number'|'Time'|'Message']
 *
 * The listing is a plain table rendered server-side, and so is every number
 * page, so both halves of this provider work without a JS engine.
 *
 * One thing to be careful about: the listing is titled "usa" but it is not a
 * US-only listing. It also carries a Canadian number (14504894173), two
 * German ones and a UK one. Canada shares country code +1, so 14504894173
 * passes every "is this a US number" test there is — it is eleven digits that
 * start with a 1. The country flag in the row is therefore the only reliable
 * discriminator, and this provider keys off it rather than off the number.
 */
final class ReceiveSmsOnlineProvider extends AbstractProvider
{
    protected const SLUG = 'receivesmsonline.net';
    protected const NAME = 'Receive SMS Online';

    protected string $baseUrl = 'https://www.receivesmsonline.net';

    /**
     * How deeply the message bodies are entity-encoded.
     *
     * The site escapes twice over: an apostrophe reaches us as "&#38;#39;".
     * The first pass is the parser decoding the document, so the text handed
     * to us still needs a few more. Bounded so a pathological body cannot
     * spin here.
     */
    private const DECODE_PASSES = 4;

    public function getUsNumbers(int $limit = 30): array
    {
        $html  = $this->http->getHtml($this->baseUrl . '/usa.html', $this->baseUrl . '/');
        $xpath = $this->xpath($html);
        $rows  = $xpath->query('//table//tr');

        $numbers = [];
        $seen    = [];

        foreach ($rows as $row) {
            // The flag is what makes a row US. See the class comment.
            $flag = $xpath->query('.//img[@title="USA"]', $row)->item(0);
            if (!$flag) {
                continue;
            }

            $link = $xpath->query('.//a[contains(@href, "/receive-sms-online-")]', $row)->item(0);
            if (!$link || !preg_match('#/receive-sms-online-(\d+)\.html#', $link->getAttribute('href'), $m)) {
                continue;
            }

            // requireUs() is a second gate, not the primary one: it rejects
            // the German and UK numbers outright but cannot tell a Canadian
            // number from an American one.
            if (!PhoneNumber::isUs($m[1])) {
                continue;
            }

            $number = PhoneNumber::us($m[1]);
            if (isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;

            $numbers[] = [
                'source'    => self::SLUG,
                'number'    => $number,
                // The anchor is the one carrying the number, "+12532058123".
                'display'   => self::clean($link->textContent),
                'link'      => $this->numberUrl($number),
                'messages'  => self::messageTotal($xpath, $row),
                // The listing carries no timestamp, only a lifetime total.
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
        $html   = $this->http->getHtml($this->numberUrl($number), $this->baseUrl . '/usa.html');
        $xpath  = $this->xpath($html);
        $rows   = $xpath->query('//table//tr[td[@data-title="Message"]]');

        $messages = [];

        foreach ($rows as $row) {
            $text = self::decode($this->cell($xpath, $row, 'Message'));
            if ($text === '') {
                continue;
            }

            $messages[] = [
                'source' => self::SLUG,
                // Senders arrive pre-masked, e.g. "1760209XXXX".
                'from'   => $this->cell($xpath, $row, 'From Number'),
                'time'   => $this->cell($xpath, $row, 'Time'),
                'text'   => $text,
                'code'   => CodeExtractor::fromText($text),
            ];

            if (count($messages) >= $limit) {
                break;
            }
        }

        return $messages;
    }

    private function numberUrl(string $number): string
    {
        return $this->baseUrl . '/receive-sms-online-' . $number . '.html';
    }

    /** Cell text for one of the data-title labelled columns, cleaned. */
    private function cell(\DOMXPath $xpath, \DOMNode $row, string $title): string
    {
        $cell = $xpath->query('.//td[@data-title="' . $title . '"]', $row)->item(0);

        return $cell ? self::clean($cell->textContent) : '';
    }

    /**
     * The lifetime message count from "(USA - Received 4620 messages in
     * total.)", or null when the row does not state one.
     */
    private static function messageTotal(\DOMXPath $xpath, \DOMNode $row): ?int
    {
        $node = $xpath->query('.//span', $row)->item(0);
        if (!$node || !preg_match('/\d+/', $node->textContent, $m)) {
            return null;
        }

        return (int) $m[0];
    }

    /** Unwrap the site's repeated entity encoding. */
    private static function decode(string $text): string
    {
        for ($i = 0; $i < self::DECODE_PASSES; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return self::clean($text);
    }
}
