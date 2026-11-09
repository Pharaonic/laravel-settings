<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use Symfony\Component\Process\Process;

/*
 * Separate PHP processes, each with its own connection, write one key at the
 * same moment. On SQLite the database file serializes the writes; on MySQL
 * and PostgreSQL (SETTINGS_TEST_DB) they really run in parallel.
 */
final class ConcurrencyTest extends TestCase
{
    /**
     * Start the workers at the same moment and collect what each printed.
     *
     * @param  array<string, string>  $env
     * @param  list<array{0: string, 1: string}>  $values  kind and value per worker
     * @return list<string>
     */
    private function race(array $env, string $key, array $values): array
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

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $results[] = trim($process->getOutput());
        }

        return $results;
    }

    public function test_simultaneous_first_writes_create_one_row(): void
    {
        $env = $this->useSharedDatabase();

        $results = $this->race($env, 'counter', array_map(function (int $i) {
            return ['int', (string) $i];
        }, range(1, 8)));

        $this->assertSame(array_fill(0, 8, 'integer'), $results);
        $this->assertSame(1, Setting::query()->where('key', 'counter')->count());
        $this->assertIsInt(settings()->getValue('counter'));
    }

    public function test_simultaneous_writes_of_two_types_leave_one_stable_type(): void
    {
        $env = $this->useSharedDatabase();
        settings()->setValue('promoted', null);

        $values = [];

        for ($i = 0; $i < 8; $i++) {
            $values[] = $i % 2 === 0 ? ['int', '1'] : ['string', 'one'];
        }

        $results = $this->race($env, 'promoted', $values);
        $type = Setting::query()->where('key', 'promoted')->value('type');
        $winners = array_filter($results, function (string $result) {
            return $result !== 'mismatch';
        });

        $this->assertContains($type, ['integer', 'string']);
        $this->assertSame(array_fill(0, count($winners), $type), array_values($winners));
        $this->assertCount(4, $winners);
        $this->assertSame(1, Setting::query()->where('key', 'promoted')->count());
    }
}
