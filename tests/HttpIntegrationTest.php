<?php

declare(strict_types=1);

use FreeSms\Http\HttpClient;

final class HttpIntegrationTest
{
    private function server(callable $test): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            throw new RuntimeException($error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $counter = tempnam(sys_get_temp_dir(), 'sms-counter-');
        $log = tempnam(sys_get_temp_dir(), 'sms-server-');
        $command = [PHP_BINARY];
        if (php_ini_loaded_file()) {
            array_push($command, '-c', php_ini_loaded_file());
        }
        array_push($command, '-S', $address, __DIR__ . '/support/router.php');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, array_merge(getenv(), ['SMS_TEST_COUNTER' => $counter]));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start local fixture server');
        }
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($i = 0; $i < 100; $i++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20_000);
            }
            assertTrue($ready, 'fixture server started');
            $test('http://' . $address . '/', $counter);
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($counter);
            unlink($log);
        }
    }

    public function testCacheSeparatesHtmlAndJson(): void
    {
        $this->server(function (string $url): void {
            $http = new HttpClient();
            assertSame('<p>html</p>', $http->getHtml($url . '?action=fixture'));
            assertSame(['kind' => 'json'], $http->getJson($url . '?action=fixture'));
        });
    }

    public function testPermanentErrorsAreNotRetriedAndTransientErrorsAre(): void
    {
        $this->server(function (string $url, string $counter): void {
            try {
                (new HttpClient())->getHtml($url . '?action=fixture&counter=1&status=403');
                throw new RuntimeException('Expected HTTP error');
            } catch (RuntimeException $e) {
                assertTrue(str_contains($e->getMessage(), 'HTTP 403'));
            }
            assertSame('1', file_get_contents($counter));
            file_put_contents($counter, '0');
            assertSame('retried', (new HttpClient())->getHtml($url . '?action=fixture&counter=1&status=retry'));
            assertSame('2', file_get_contents($counter));
        });
    }

    public function testDecodedResponsesAreBoundedAndUnsafeRedirectsFail(): void
    {
        $this->server(function (string $url): void {
            foreach (['&size=2000', '&size=2000&gzip=1', '&redirect=1&status=302'] as $query) {
                try {
                    (new HttpClient(5, 0, 1000))->getHtml($url . '?action=fixture' . $query);
                    throw new RuntimeException('Expected bounded response failure');
                } catch (RuntimeException $e) {
                    assertTrue(str_contains($e->getMessage(), 'exceeds') || str_contains($e->getMessage(), 'Failed to fetch'));
                }
            }
        });
    }

    public function testApiValidationAndMethodHandlingStayJson(): void
    {
        $this->server(function (string $url): void {
            foreach ([
                ['GET', '?action[]=sources', 400], ['GET', '?action=sources&source[]=all', 400],
                ['GET', '?action=sources&limit[]=1', 400], ['GET', '?action=sources&number[]=1', 400],
                ['GET', '?action=sources&limit=no', 400], ['GET', '?action=unknown', 400],
                ['GET', '?action=messages&number=123', 400], ['POST', '?action=sources', 405],
                ['GET', '?action=sources', 200], ['OPTIONS', '', 204],
            ] as [$method, $query, $expected]) {
                $ch = curl_init($url . $query);
                curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true]);
                $body = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                unset($ch);
                assertSame($expected, $status, $method . $query);
                if ($expected === 204) {
                    assertSame('', $body);
                } else {
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    assertSame($expected === 200, $data['ok']);
                    assertTrue(array_key_exists('error', $data));
                }
            }
        });
    }
}
