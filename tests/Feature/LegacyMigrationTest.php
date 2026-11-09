<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\Fixtures\LegacySchema;
use Pharaonic\Laravel\Settings\Tests\Fixtures\UuidModel;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class LegacyMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LegacySchema::create();
    }

    /**
     * Run the command and return its exit code and output.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function migrate(array $options = []): array
    {
        $code = Artisan::call('settings:migrate-legacy', $options);

        return [$code, Artisan::output()];
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        LegacySchema::insert(['name' => 'site.name', 'value' => 'Pharaonic']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'dark', 'settingable_type' => Company::class, 'settingable_id' => '12']);

        [$code, $output] = $this->migrate();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Legacy rows read: 2. Rows to write: 2. Scopes: 2.', $output);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertTrue(Schema::hasColumn('settings', 'settingable_type'));
        $this->assertFalse(Schema::hasTable('settings_legacy'));
    }

    public function test_apply_converts_side_by_side_and_keeps_a_backup(): void
    {
        Relation::morphMap(['company' => Company::class]);
        $uuid = '9b2f0c1e-0000-4000-8000-000000000000';

        LegacySchema::insert(['name' => 'site.name', 'value' => 'Pharaonic', 'created_at' => '2022-05-01 10:00:00', 'updated_at' => '2022-06-01 10:00:00']);
        LegacySchema::insert(['name' => 'site.flag', 'value' => '1']);
        LegacySchema::insert(['name' => 'site.empty', 'value' => '']);
        LegacySchema::insert(['name' => 'site.unset']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'dark', 'settingable_type' => Company::class, 'settingable_id' => '12']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'light', 'settingable_type' => 'company', 'settingable_id' => '13']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'blue', 'settingable_type' => UuidModel::class, 'settingable_id' => $uuid]);

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Converted 7 rows', $output);
        $this->assertStringContainsString('Checksum: ', $output);
        $this->assertStringContainsString('To undo', $output);
        $this->assertTrue(Schema::hasTable('settings_legacy'));
        $this->assertSame(7, DB::table('settings_legacy')->count());

        $this->assertSame('Pharaonic', settings()->getValue('site.name'));
        $this->assertSame('1', settings()->getValue('site.flag'));
        $this->assertSame('', settings()->getValue('site.empty'));
        $this->assertTrue(settings()->hasValue('site.unset'));
        $this->assertNull(settings()->getValue('site.unset', 'default'));
        $this->assertSame('dark', settings('company:12')->getValue('theme'));
        $this->assertSame('light', settings('company:13')->getValue('theme'));
        $this->assertSame('blue', settings(UuidModel::class.':'.$uuid)->getValue('theme'));

        $row = Setting::for()->where('key', 'site.name')->firstOrFail();

        $this->assertSame('string', $row->type);
        $this->assertTrue($row->nullable);
        $this->assertSame('2022-05-01 10:00:00', (string) $row->created_at);
        $this->assertSame('2022-06-01 10:00:00', (string) $row->updated_at);
    }

    public function test_a_converted_model_scope_is_the_model_scope(): void
    {
        $company = Company::create();
        LegacySchema::insert(['name' => 'theme', 'value' => 'dark', 'settingable_type' => Company::class, 'settingable_id' => (string) $company->getKey()]);

        $this->migrate(['--apply' => true]);

        $this->assertSame('dark', $company->getSetting('theme'));
    }

    public function test_a_rerun_changes_nothing(): void
    {
        LegacySchema::insert(['name' => 'site.name', 'value' => 'Pharaonic']);
        $this->migrate(['--apply' => true]);

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('already has the pharaonic/laravel-settings 2 schema', $output);
        $this->assertSame(1, Setting::query()->count());
    }

    public function test_ambiguous_json_blocks_the_conversion_by_default(): void
    {
        LegacySchema::insert(['name' => 'object', 'value' => '{"a":1}']);
        LegacySchema::insert(['name' => 'list', 'value' => '["ar","en"]']);
        LegacySchema::insert(['name' => 'broken', 'value' => '{not json']);

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('3 row(s) block the conversion', $output);
        $this->assertStringContainsString('JSON object', $output);
        $this->assertStringContainsString('JSON array', $output);
        $this->assertTrue(Schema::hasColumn('settings', 'settingable_type'));
    }

    public function test_ambiguous_json_can_be_kept_as_text(): void
    {
        LegacySchema::insert(['name' => 'object', 'value' => '{"a":1}']);
        LegacySchema::insert(['name' => 'broken', 'value' => '{not json']);

        [$code] = $this->migrate(['--apply' => true, '--ambiguous' => 'string']);

        $this->assertSame(0, $code);
        $this->assertSame('{"a":1}', settings()->getValue('object'));
        $this->assertSame('{not json', settings()->getValue('broken'));
    }

    public function test_ambiguous_json_can_be_decoded(): void
    {
        LegacySchema::insert(['name' => 'object', 'value' => '{"a":1}']);
        LegacySchema::insert(['name' => 'list', 'value' => '["ar","en"]']);

        [$code] = $this->migrate(['--apply' => true, '--ambiguous' => 'decode']);

        $this->assertSame(0, $code);
        $this->assertSame(['a' => 1], settings()->getValue('object'));
        $this->assertSame(['ar', 'en'], settings()->getValue('list'));
    }

    public function test_invalid_json_cannot_be_decoded(): void
    {
        LegacySchema::insert(['name' => 'broken', 'value' => '{not json']);

        [$code, $output] = $this->migrate(['--apply' => true, '--ambiguous' => 'decode']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not valid JSON', $output);
    }

    public function test_duplicates_block_the_conversion_by_default(): void
    {
        LegacySchema::insert(['name' => 'theme', 'value' => 'old']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'new']);

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('also stored by row 1', $output);
    }

    public function test_duplicates_can_keep_the_latest_row(): void
    {
        LegacySchema::insert(['name' => 'theme', 'value' => 'newest', 'updated_at' => '2024-01-01 00:00:00']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'older', 'updated_at' => '2023-01-01 00:00:00']);
        LegacySchema::insert(['name' => 'theme', 'value' => 'tie later id', 'updated_at' => '2024-01-01 00:00:00']);

        [$code, $output] = $this->migrate(['--apply' => true, '--duplicates' => 'latest']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Duplicate rows left out (they stay in the backup table): 1, 2.', $output);
        $this->assertSame('tie later id', settings()->getValue('theme'));
    }

    public function test_an_unknown_model_type_needs_a_map(): void
    {
        LegacySchema::insert(['name' => 'theme', 'value' => 'dark', 'settingable_type' => 'App\\Old\\User', 'settingable_id' => '5']);

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--map', $output);

        [$code] = $this->migrate(['--apply' => true, '--map' => ['App\\Old\\User=user']]);

        $this->assertSame(0, $code);
        $this->assertSame('dark', settings('user:5')->getValue('theme'));
    }

    public function test_half_set_model_columns_are_refused(): void
    {
        LegacySchema::insert(['name' => 'theme', 'value' => 'dark', 'settingable_type' => Company::class]);

        [$code, $output] = $this->migrate();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('must both be set', $output);
    }

    public function test_invalid_keys_are_refused(): void
    {
        LegacySchema::insert(['name' => 'bad..key', 'value' => 'x']);

        [$code, $output] = $this->migrate();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('bad..key', $output);
    }

    public function test_definitions_type_the_converted_values(): void
    {
        config(['pharaonic.settings.definitions' => [
            'limit' => ['type' => 'integer'],
            'enabled' => ['type' => 'boolean'],
            'label' => ['type' => 'integer'],
        ]]);
        LegacySchema::insert(['name' => 'limit', 'value' => '20']);
        LegacySchema::insert(['name' => 'enabled', 'value' => '1']);
        LegacySchema::insert(['name' => 'label', 'value' => 'twenty']);

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot be safely converted to the defined type [integer]', $output);

        DB::table('settings')->where('name', 'label')->delete();

        [$code] = $this->migrate(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(20, settings()->getValue('limit'));
        $this->assertTrue(settings()->getValue('enabled'));
        $this->assertFalse(Setting::for()->where('key', 'limit')->firstOrFail()->nullable);
    }

    public function test_an_existing_backup_table_stops_the_conversion(): void
    {
        LegacySchema::insert(['name' => 'theme', 'value' => 'dark']);
        Schema::create('settings_legacy', function ($table) {
            $table->increments('id');
        });

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('already exists', $output);
        $this->assertTrue(Schema::hasColumn('settings', 'settingable_type'));

        [$code] = $this->migrate(['--apply' => true, '--backup' => 'settings_v1']);

        $this->assertSame(0, $code);
        $this->assertTrue(Schema::hasTable('settings_v1'));
    }

    public function test_an_unknown_table_is_left_alone(): void
    {
        Schema::drop('settings');
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('something');
        });

        [$code, $output] = $this->migrate(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('neither the 1.x nor the 2 schema', $output);
    }

    public function test_invalid_policies_are_refused(): void
    {
        [$code] = $this->migrate(['--duplicates' => 'first']);

        $this->assertSame(1, $code);
    }

    public function test_the_values_are_not_printed(): void
    {
        LegacySchema::insert(['name' => 'api.token', 'value' => '{"secret":"s3cret"}']);

        [, $output] = $this->migrate();

        $this->assertStringNotContainsString('s3cret', $output);
    }
}
