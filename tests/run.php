<?php

/**
 * Minimal test runner.
 *
 * The project deliberately ships with no Composer dependencies, so tests use
 * plain assertions rather than PHPUnit. Each *Test.php file declares a class
 * whose public methods starting with "test" are executed.
 *
 * Usage:
 *   php tests/run.php            # run everything
 *   php tests/run.php Code       # run only classes matching a substring
 *
 * Exits non-zero when any assertion fails, so it works in CI unchanged.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

final class TestRunner
{
    private int $passed = 0;

    /** @var array<int,string> */
    private array $failures = [];

    public function run(string $class, string $filter): void
    {
        $methods = array_filter(
            get_class_methods($class),
            static fn (string $m): bool => str_starts_with($m, 'test')
        );

        if ($methods === []) {
            $this->failures[] = $class . ': no test* methods';
            return;
        }

        echo "\n\033[1m" . $class . "\033[0m\n";

        foreach ($methods as $method) {
            $instance = new $class();
            try {
                $instance->$method();
                $this->passed++;
                echo "  \033[32m✓\033[0m " . $this->label($method) . "\n";
            } catch (Throwable $e) {
                $this->failures[] = $class . '::' . $method . "\n      " . $e->getMessage();
                echo "  \033[31m✗\033[0m " . $this->label($method) . "\n";
                echo "      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n";
            }
        }
    }

    public function report(): int
    {
        $total = $this->passed + count($this->failures);

        echo "\n" . str_repeat('-', 60) . "\n";

        if ($this->failures === []) {
            echo "\033[32mOK\033[0m — {$this->passed} passed, 0 failed\n";
            return 0;
        }

        echo "\033[31mFAILED\033[0m — {$this->passed} passed, "
            . count($this->failures) . " failed (of {$total})\n";
        return 1;
    }

    private function label(string $method): string
    {
        return trim(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', substr($method, 4)) ?? ''));
    }
}

/**
 * @throws RuntimeException when the expectation fails
 */
function assertSame(mixed $expected, mixed $actual, string $context = ''): void
{
    if ($expected === $actual) {
        return;
    }

    throw new RuntimeException(sprintf(
        "%sexpected %s, got %s",
        $context === '' ? '' : $context . ': ',
        var_export($expected, true),
        var_export($actual, true)
    ));
}

function assertTrue(bool $condition, string $context = ''): void
{
    assertSame(true, $condition, $context);
}

$filter  = $argv[1] ?? '';
$runner  = new TestRunner();
$classes = [];

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require_once $file;
    $classes[] = basename($file, '.php');
}

sort($classes);

foreach ($classes as $name) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    if (!class_exists($name)) {
        $runner->run('\\' . $name, $filter);
        continue;
    }
    $runner->run($name, $filter);
}

exit($runner->report());
