<?php

// Local synthetic transport fixture. Never contacts external services.
if (($_GET['action'] ?? '') === 'fixture') {
    if (isset($_GET['counter'])) {
        $path = getenv('SMS_TEST_COUNTER');
        $count = is_file($path) ? (int) file_get_contents($path) : 0;
        file_put_contents($path, (string) ($count + 1));
        if (($_GET['status'] ?? '') === 'retry') {
            http_response_code($count === 0 ? 503 : 200);
            echo 'retried';
            return;
        }
    }
    http_response_code((int) ($_GET['status'] ?? 200));
    if (isset($_GET['redirect'])) {
        header('Location: file:///etc/passwd');
        return;
    }
    if (isset($_GET['size'])) {
        $body = str_repeat('x', (int) $_GET['size']);
        if (isset($_GET['gzip'])) {
            header('Content-Encoding: gzip');
            $body = gzencode($body);
        }
        echo $body;
        return;
    }
    echo str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ? '{"kind":"json"}' : '<p>html</p>';
    return;
}
require dirname(__DIR__, 2) . '/public/index.php';
