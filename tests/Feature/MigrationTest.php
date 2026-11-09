<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Settings\Tests\Fixtures\LegacySchema;

function migration_migration(\Pharaonic\Laravel\Settings\Tests\TestCase $test): object
{
    require_once __DIR__.'/../../database/migrations/2021_02_01_000032_create_settings_table.php';

    return new \CreateSettingsTable;
}

test('it refuses to run over a legacy table', function () {
    LegacySchema::create();

    try {
        migration_migration($this)->up();
        $this->fail('The migration ran over the legacy table.');
    } catch (RuntimeException $exception) {
        $this->assertStringContainsString('settings:migrate-legacy', $exception->getMessage());
    }

    $this->assertTrue(Schema::hasColumn('settings', 'settingable_type'));
});

test('it creates and drops the configured table', function () {
    config(['pharaonic.settings.table' => 'app_settings']);
    $migration = migration_migration($this);

    $migration->up();

    $this->assertTrue(Schema::hasTable('app_settings'));

    $migration->down();

    $this->assertFalse(Schema::hasTable('app_settings'));
});

test('the global identifier is the column default', function () {
    DB::table('settings')->insert(['key' => 'k', 'value' => '1']);

    $this->assertSame('__global__', DB::table('settings')->value('identifier'));
});

test('the package reads and writes the configured table', function () {
    config(['pharaonic.settings.table' => 'app_settings']);
    migration_migration($this)->up();

    settings()->setValue('theme', 'dark');

    $this->assertSame(1, DB::table('app_settings')->count());
    $this->assertSame(0, DB::table('settings')->count());
    $this->assertSame('dark', settings()->getValue('theme'));
});
