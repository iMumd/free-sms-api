<?php

/**
 * Minimal PSR-4 autoloader for the FreeSms\ namespace.
 *
 * Use this when Composer is not installed. If you run `composer install`,
 * prefer vendor/autoload.php instead (it is loaded automatically below when present).
 */

declare(strict_types=1);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix  = 'FreeSms\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
