<?php

require __DIR__ . '/../vendor/autoload.php';

$testCases = array_diff(scandir(__DIR__), ['.', '..', 'bootstrap.php']);

foreach ($testCases as $test) {
    if (!str_ends_with($test, '.php')) {
        continue;
    }

    require_once __DIR__ . '/' . $test;
}
