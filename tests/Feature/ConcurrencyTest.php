<?php

use Pharaonic\Laravel\Settings\Models\Setting;
use Symfony\Component\Process\Process;

/*
 * Separate PHP processes, each with its own connection, write one key at the
 * same moment. On SQLite the database file serializes the writes; on MySQL
 * and PostgreSQL (SETTINGS_TEST_DB) they really run in parallel.
 */

/**
 * Start the workers at the same moment and collect what each printed.
 *
 * @param  array<string, string>  $env
 * @param  list<array{0: string, 1: string}>  $values  kind and value per worker
 * @return list<string>
 */
function concurrency_race(\Pharaonic\Laravel\Settings\Tests\TestCase $test, array $env, string $key, array $values): array
{
    // Leave the workers time to boot, then let them all start together.
    $start = (string) (microtime(true) + 3);
    $processes = [];

    foreach ($values as [$kind, $value]) {
        $process = new Process([PHP_BINARY, __DIR__.'/../Processes/worker.php', $key, $kind, $value, $start], null, $env, null, 60);
        $process->start();
        $processes[] = $process;
    }

    $results = [];

    foreach ($processes as $process) {
        $process->wait();

        $test->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $results[] = trim($process->getOutput());
    }

    return $results;
}

test('simultaneous first writes create one row', function () {
    $env = $this->useSharedDatabase();

    $results = concurrency_race($this, $env, 'counter', array_map(function (int $i) {
        return ['int', (string) $i];
    }, range(1, 8)));

    $this->assertSame(array_fill(0, 8, 'integer'), $results);
    $this->assertSame(1, Setting::query()->where('key', 'counter')->count());
    $this->assertIsInt(settings()->getValue('counter'));
});

test('simultaneous writes of two types leave one stable type', function () {
    $env = $this->useSharedDatabase();
    settings()->setValue('promoted', null);

    $values = [];

    for ($i = 0; $i < 8; $i++) {
        $values[] = $i % 2 === 0 ? ['int', '1'] : ['string', 'one'];
    }

    $results = concurrency_race($this, $env, 'promoted', $values);
    $type = Setting::query()->where('key', 'promoted')->value('type')?->value;
    $winners = array_filter($results, function (string $result) {
        return $result !== 'mismatch';
    });

    $this->assertContains($type, ['integer', 'string']);
    $this->assertSame(array_fill(0, count($winners), $type), array_values($winners));
    $this->assertCount(4, $winners);
    $this->assertSame(1, Setting::query()->where('key', 'promoted')->count());
});
