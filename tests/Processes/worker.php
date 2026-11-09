<?php

/*
 * One worker of the concurrency tests: boots the package, waits for the shared
 * start time, then stores the value in the global scope and prints the type it
 * ended up with, or "mismatch" when the stored type refused the value.
 *
 * Usage: php worker.php <key> <int|string> <value> <start time>
 */

use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Tests\Processes\WorkerApplication;

require __DIR__.'/../../vendor/autoload.php';

[, $key, $kind, $value, $start] = $argv;

(new WorkerApplication('worker'))->createApplication();

if ((float) $start > microtime(true)) {
    time_sleep_until((float) $start);
}

try {
    echo settings()->setValue($key, $kind === 'int' ? (int) $value : $value)->type;
} catch (SettingTypeMismatchException $exception) {
    echo 'mismatch';
}
