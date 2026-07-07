<?php

/**
 * Command-line example that uses the library directly (no web server).
 *
 * Usage:
 *   php examples/cli.php sources
 *   php examples/cli.php numbers [source] [limit]
 *   php examples/cli.php messages <number> [source] [limit]
 *   php examples/cli.php latest [source] [limit]
 *   php examples/cli.php random [source] [limit]
 *
 * Examples:
 *   php examples/cli.php numbers all 5
 *   php examples/cli.php messages 16465180948 receive-sms.cc 5
 *   php examples/cli.php latest freephonenum.com 5
 *   php examples/cli.php random all 5
 */

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use FreeSms\SmsAggregator;

$command = $argv[1] ?? 'latest';
$sms     = new SmsAggregator();

function out(mixed $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

try {
    switch ($command) {
        case 'sources':
            out($sms->sources());
            break;

        case 'numbers':
            $source = $argv[2] ?? 'all';
            $limit  = (int) ($argv[3] ?? 10);
            out($sms->numbers($source, $limit));
            break;

        case 'messages':
            $number = $argv[2] ?? '';
            if ($number === '') {
                fwrite(STDERR, "Usage: php examples/cli.php messages <number> [source] [limit]\n");
                exit(1);
            }
            $source = $argv[3] ?? 'all';
            $limit  = (int) ($argv[4] ?? 20);
            out($sms->messages($number, $source, $limit));
            break;

        case 'latest':
            $source = $argv[2] ?? 'all';
            $limit  = (int) ($argv[3] ?? 20);
            out($sms->latest($source, $limit));
            break;

        case 'random':
            $source = $argv[2] ?? 'all';
            $limit  = (int) ($argv[3] ?? 20);
            $result = $sms->random($source, $limit);
            if ($result === null) {
                fwrite(STDERR, "No numbers available.\n");
                exit(1);
            }
            out($result);
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
