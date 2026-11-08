<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/TestCase.php';

$files = glob(__DIR__ . '/{Unit,Integration}/*Test.php', GLOB_BRACE) ?: [];
sort($files);
foreach ($files as $file) {
    require $file;
}

$classes = array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'App\\Tests\\') && str_ends_with($class, 'Test'));
$failures = 0;
$executed = 0;

foreach ($classes as $class) {
    $test = new $class();
    foreach (get_class_methods($test) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $executed++;
        try {
            $test->{$method}();
            echo "OK   {$class}::{$method}\n";
        } catch (Throwable $exception) {
            $failures++;
            echo "FAIL {$class}::{$method}\n     {$exception->getMessage()}\n";
        }
    }
}

echo sprintf("\n%d Tests, %d Fehler\n", $executed, $failures);
exit($failures === 0 ? 0 : 1);
