<?php

/**
 * JSON front controller for the Free SMS API.
 *
 * Every response has the shape:
 *   { "ok": bool, "data": mixed|null, "error": string|null }
 *
 * Endpoints (all via query string on this single entry point):
 *   ?action=sources
 *   ?action=numbers   [&source=all|<slug>] [&limit=N]
 *   ?action=number    [&source=all|<slug>]
 *   ?action=messages   &number=<digits> [&source=all|<slug>] [&limit=N]
 *   ?action=latest    [&source=all|<slug>] [&limit=N]
 *   ?action=random    [&source=all|<slug>] [&limit=N]
 *
 * See docs/API.md for the full reference and examples.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use FreeSms\SmsAggregator;
use FreeSms\Support\PhoneNumber;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Emit a JSON envelope and stop.
 */
function respond(bool $ok, mixed $data = null, ?string $error = null, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'data' => $data, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

$action = (string) ($_GET['action'] ?? 'numbers');
$source = (string) ($_GET['source'] ?? 'all');
$limit  = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$sms    = new SmsAggregator();

try {
    switch ($action) {
        case 'sources':
            respond(true, $sms->sources());

        case 'numbers':
            $result = $sms->numbers($source, $limit);
            $errors = $result['errors'] !== []
                ? json_encode($result['errors'], JSON_UNESCAPED_UNICODE)
                : null;
            respond($result['numbers'] !== [] || $result['errors'] === [], $result['numbers'], $errors);

        case 'number':
            $number = $sms->firstNumber($source);
            $number !== null
                ? respond(true, $number)
                : respond(false, null, 'No numbers available.', 404);

        case 'messages':
            $number = (string) ($_GET['number'] ?? '');
            if ($number === '') {
                respond(false, null, "The 'number' parameter is required.", 400);
            }
            if (!PhoneNumber::isUs($number)) {
                respond(
                    false,
                    null,
                    'The \'number\' parameter must be a US phone number of 10 digits, '
                    . 'optionally with a leading country code.',
                    400
                );
            }
            $result = $sms->messages($number, $source, $limit);
            if ($result['messages'] !== []) {
                respond(true, [
                    'source'   => $result['source'],
                    'number'   => $result['number'],
                    'messages' => $result['messages'],
                ]);
            }
            $errors = $result['errors'] !== []
                ? json_encode($result['errors'], JSON_UNESCAPED_UNICODE)
                : 'No messages found for this number.';
            respond(false, ['number' => $result['number'], 'messages' => []], $errors, 404);

        case 'latest':
            $latest = $sms->latest($source, $limit);
            $latest !== null
                ? respond(true, $latest)
                : respond(false, null, 'Could not fetch recent messages.', 502);

        case 'random':
            $random = $sms->random($source, $limit);
            $random !== null
                ? respond(true, $random)
                : respond(false, null, 'No numbers available.', 404);

        default:
            respond(false, null, "Unknown action: {$action}", 400);
    }
} catch (InvalidArgumentException $e) {
    // A malformed request (unknown source, unusable number) is the caller's
    // fault, not an upstream failure, so it must not be reported as 502.
    respond(false, null, $e->getMessage(), 400);
} catch (Throwable $e) {
    respond(false, null, $e->getMessage(), 502);
}
