<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Settings\Tests\Fixtures\LegacySchema;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use RuntimeException;

final class MigrationTest extends TestCase
{
    private function migration(): object
    {
        require_once __DIR__.'/../../database/migrations/2021_02_01_000032_create_settings_table.php';

        return new \CreateSettingsTable;
    }

    public function test_it_refuses_to_run_over_a_legacy_table(): void
    {
        LegacySchema::create();

        try {
            $this->migration()->up();
            $this->fail('The migration ran over the legacy table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('settings:migrate-legacy', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('settings', 'settingable_type'));
    }

    public function test_it_creates_and_drops_the_configured_table(): void
    {
        config(['pharaonic.settings.table' => 'app_settings']);
        $migration = $this->migration();

        $migration->up();

        $this->assertTrue(Schema::hasTable('app_settings'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('app_settings'));
    }

    public function test_the_global_identifier_is_the_column_default(): void
    {
        DB::table('settings')->insert(['key' => 'k', 'value' => '1']);

        $this->assertSame('__global__', DB::table('settings')->value('identifier'));
    }

    public function test_the_package_reads_and_writes_the_configured_table(): void
    {
        config(['pharaonic.settings.table' => 'app_settings']);
        $this->migration()->up();

        settings()->setValue('theme', 'dark');

        $this->assertSame(1, DB::table('app_settings')->count());
        $this->assertSame(0, DB::table('settings')->count());
        $this->assertSame('dark', settings()->getValue('theme'));
    }
}
