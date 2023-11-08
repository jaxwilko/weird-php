#!/usr/bin/env php
<?php

// Define flag
const WEIRD_SPAWNED_PROCESS = 1;

// Start processing the request
stream_set_blocking(STDIN, false);
$input = stream_get_contents(STDIN);

try {
    $input = unserialize($input);
    $bootstrap = $input['bootstrap'] ?? null;
    $run = $input['execute'] ?? null;
} catch (\Throwable $e) {
    throw new \RuntimeException('unable to decode class');
}

if ($bootstrap && !file_exists($bootstrap)) {
    throw new \RuntimeException('Bootstrap `' . $bootstrap . '` not found');
}

// Load autoloader
require_once $bootstrap;

if (!class_exists($run)) {
    throw new \RuntimeException('Class `' . $run . '` not found');
}

// Define method for output
if (!function_exists('processWrite')) {
    function processWrite(mixed $data): void
    {
        fwrite(STDOUT, \Weird\Process::wrap($data));
    }
}

$obj = new $run();

if (!$obj instanceof \Weird\Contracts\Processable) {
    throw new \RuntimeException('Class not processable');
}

processWrite(['running' => true]);

$exit = $obj->handle();

exit($exit);
