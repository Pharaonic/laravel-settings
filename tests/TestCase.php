<?php

namespace Pharaonic\Laravel\Settings\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Pharaonic\Laravel\Settings\SettingsServiceProvider;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Schema;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetMorphMap();
        $this->freshDatabase();
    }

    protected function tearDown(): void
    {
        $this->resetMorphMap();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [SettingsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connection());
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', ['driver' => 'array', 'serialize' => true]);
        $app['config']->set('cache.stores.secondary', ['driver' => 'array', 'serialize' => true]);
    }

    /**
     * The test database: in-memory SQLite, unless SETTINGS_TEST_DB selects
     * mysql or pgsql.
     *
     * @return array<string, mixed>
     */
    public static function connection(): array
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return is_string($value) && $value !== '' ? $value : $default;
        };

        switch ($env('SETTINGS_TEST_DB', 'sqlite')) {
            case 'mysql':
                return [
                    'driver' => 'mysql',
                    'host' => $env('SETTINGS_TEST_DB_HOST', '127.0.0.1'),
                    'port' => $env('SETTINGS_TEST_DB_PORT', '3306'),
                    'database' => $env('SETTINGS_TEST_DB_DATABASE', 'settings'),
                    'username' => $env('SETTINGS_TEST_DB_USERNAME', 'root'),
                    'password' => $env('SETTINGS_TEST_DB_PASSWORD', ''),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ];
            case 'pgsql':
                return [
                    'driver' => 'pgsql',
                    'host' => $env('SETTINGS_TEST_DB_HOST', '127.0.0.1'),
                    'port' => $env('SETTINGS_TEST_DB_PORT', '5432'),
                    'database' => $env('SETTINGS_TEST_DB_DATABASE', 'settings'),
                    'username' => $env('SETTINGS_TEST_DB_USERNAME', 'postgres'),
                    'password' => $env('SETTINGS_TEST_DB_PASSWORD', ''),
                    'charset' => 'utf8',
                    'prefix' => '',
                    'schema' => 'public',
                    'sslmode' => 'prefer',
                ];
            default:
                return [
                    'driver' => 'sqlite',
                    'database' => $env('SETTINGS_TEST_DB_DATABASE', ':memory:'),
                    'prefix' => '',
                ];
        }
    }

    /**
     * Drop every table, run the package migration, and create the fixture tables.
     */
    protected function freshDatabase(): void
    {
        $this->app->make(Kernel::class)->call('migrate:fresh');

        Schema::create();
    }

    /**
     * Move the tests to a database other processes can open: a SQLite file
     * instead of the in-memory database. Returns the environment a worker
     * process needs to open it.
     *
     * @return array<string, string>
     */
    protected function useSharedDatabase(): array
    {
        $variables = ['SETTINGS_TEST_DB', 'SETTINGS_TEST_DB_HOST', 'SETTINGS_TEST_DB_PORT', 'SETTINGS_TEST_DB_DATABASE', 'SETTINGS_TEST_DB_USERNAME', 'SETTINGS_TEST_DB_PASSWORD'];
        $env = [];

        foreach ($variables as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }

        if ($this->app['config']->get('database.connections.testing.driver') === 'sqlite') {
            $file = tempnam(sys_get_temp_dir(), 'laravel-settings-');
            $this->assertIsString($file);

            $this->app['config']->set('database.connections.testing.database', $file);
            DB::purge('testing');
            $this->freshDatabase();

            $this->beforeApplicationDestroyed(function () use ($file) {
                DB::disconnect('testing');
                @unlink($file);
            });

            $env['SETTINGS_TEST_DB'] = 'sqlite';
            $env['SETTINGS_TEST_DB_DATABASE'] = $file;
        }

        return $env;
    }

    /**
     * Count the queries the callback runs against the settings table.
     */
    protected function countQueries(callable $callback): int
    {
        $count = 0;

        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'settings')) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    /**
     * Assert that the callback throws the exception class.
     *
     * @param  class-string<\Throwable>  $class
     */
    protected function assertThrowsException(callable $callback, string $class): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($class, $exception, get_class($exception).': '.$exception->getMessage());

            return;
        }

        $this->fail("No {$class} was thrown.");
    }

    private function resetMorphMap(): void
    {
        Relation::$morphMap = [];

        if (method_exists(Relation::class, 'requireMorphMap')) {
            Relation::requireMorphMap(false);
        }
    }
}
