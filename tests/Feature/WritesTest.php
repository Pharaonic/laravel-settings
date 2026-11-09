<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\InvalidSettingKeyException;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use RuntimeException;

final class WritesTest extends TestCase
{
    public function test_set_value_returns_the_persisted_row(): void
    {
        $setting = settings('checkout')->setValue('minimum', 100);

        $this->assertTrue($setting->exists);
        $this->assertIsInt($setting->id);
        $this->assertSame('checkout', $setting->identifier);
        $this->assertSame('minimum', $setting->key);
        $this->assertSame(100, $setting->value);
        $this->assertNotNull($setting->created_at);
        $this->assertTrue($setting->is(Setting::for('checkout')->where('key', 'minimum')->first()));
    }

    public function test_a_key_has_one_row(): void
    {
        settings()->setValue('title', 'One');
        $setting = settings()->setValue('title', 'Two');
        settings()->setValue('title', 'Two');

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('Two', $setting->value);
        $this->assertSame('Two', settings()->getValue('title'));
    }

    public function test_the_database_refuses_a_duplicate_row(): void
    {
        settings()->setValue('title', 'One');

        $this->expectException(QueryException::class);

        DB::table('settings')->insert([
            'identifier' => Setting::GLOBAL,
            'key' => 'title',
            'type' => 'string',
            'nullable' => false,
            'value' => '"Two"',
        ]);
    }

    public function test_keys_that_differ_only_by_case_are_separate_rows(): void
    {
        settings()->setValue('Theme', 'upper');
        settings()->setValue('theme', 'lower');

        $this->assertSame('upper', settings()->getValue('Theme'));
        $this->assertSame('lower', settings()->getValue('theme'));
        $this->assertSame(2, Setting::query()->count());
    }

    public function test_forget_value_only_touches_its_own_scope(): void
    {
        settings()->setValue('theme', 'global');
        settings('tenant')->setValue('theme', 'tenant');

        $this->assertTrue(settings('tenant')->forgetValue('theme'));
        $this->assertFalse(settings('tenant')->forgetValue('theme'));
        $this->assertFalse(settings('tenant')->hasValue('theme'));
        $this->assertSame('global', settings()->getValue('theme'));
    }

    public function test_set_many_stores_several_values(): void
    {
        settings()->setMany(['a' => 1, 'b' => 'two', 'c' => [3]]);

        $this->assertSame(['a' => 1, 'b' => 'two', 'c' => [3]], settings()->allValues());
    }

    public function test_set_many_stores_everything_or_nothing(): void
    {
        settings()->setValue('count', 1);

        $this->assertThrowsException(fn () => settings()->setMany(['fresh' => 'x', 'count' => 'not an int']), SettingTypeMismatchException::class);
        $this->assertFalse(settings()->hasValue('fresh'));
        $this->assertSame(1, settings()->getValue('count'));

        config(['pharaonic.settings.definitions' => ['limit' => ['type' => 'integer', 'rules' => ['max:5']]]]);

        $this->assertThrowsException(fn () => settings()->setMany(['fresh' => 'x', 'limit' => 9]), ValidationException::class);
        $this->assertFalse(settings()->hasValue('fresh'));
    }

    public function test_set_many_refuses_integer_keys(): void
    {
        $this->assertThrowsException(fn () => settings()->setMany(['a', 'b']), InvalidSettingKeyException::class);
        $this->assertThrowsException(fn () => settings()->setMany(['ok' => 1, 5 => 2]), InvalidSettingKeyException::class);
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_writes_roll_back_with_the_surrounding_transaction(): void
    {
        settings()->setValue('theme', 'light');

        try {
            DB::transaction(function () {
                settings()->setValue('theme', 'dark');
                settings()->setValue('new', 1);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            //
        }

        $this->assertSame('light', settings()->getValue('theme'));
        $this->assertFalse(settings()->hasValue('new'));
    }

    public function test_retype_value_changes_the_type_and_converts_the_value(): void
    {
        settings()->setValue('limit', '20');

        $setting = settings()->retypeValue('limit', SettingType::INTEGER);

        $this->assertSame('integer', $setting->type);
        $this->assertSame(20, $setting->value);
        $this->assertSame(20, settings()->getValue('limit'));

        $setting = settings()->retypeValue('limit', SettingType::STRING, true);

        $this->assertSame('20', $setting->value);
        $this->assertTrue($setting->nullable);
        $this->assertNull(settings()->setValue('limit', null)->value);
    }

    public function test_a_retype_that_cannot_convert_leaves_the_row_unchanged(): void
    {
        settings()->setValue('label', 'twenty');

        $this->assertThrowsException(fn () => settings()->retypeValue('label', SettingType::INTEGER), SettingTypeMismatchException::class);
        $this->assertSame('string', Setting::for()->where('key', 'label')->value('type'));
        $this->assertSame('twenty', settings()->getValue('label'));

        settings()->setValue('optional', null);
        settings()->setValue('optional', 'x');
        settings()->setValue('optional', null);

        $this->assertThrowsException(fn () => settings()->retypeValue('optional', SettingType::STRING, false), SettingTypeMismatchException::class);
    }

    public function test_retype_refuses_a_missing_setting_and_its_definition(): void
    {
        $this->assertThrowsException(fn () => settings()->retypeValue('missing', SettingType::STRING), InvalidArgumentException::class);

        settings()->setValue('pinned', 1);
        config(['pharaonic.settings.definitions' => ['pinned' => ['type' => 'integer']]]);

        $this->assertThrowsException(fn () => settings()->retypeValue('pinned', SettingType::STRING), SettingDefinitionException::class);
        $this->assertSame(1, settings()->getValue('pinned'));
    }

    public function test_a_native_save_cannot_change_the_metadata(): void
    {
        $setting = settings()->setValue('count', 1);
        $setting->type = SettingType::STRING;

        $this->assertThrowsException(fn () => $setting->save(), SettingTypeMismatchException::class);

        $setting = Setting::for()->where('key', 'count')->firstOrFail();
        $setting->nullable = true;

        $this->assertThrowsException(fn () => $setting->save(), SettingTypeMismatchException::class);
    }

    public function test_a_model_delete_forgets_the_cache(): void
    {
        $setting = settings()->setValue('theme', 'dark');
        settings()->getValue('theme');

        $setting->delete();

        $this->assertFalse(settings()->hasValue('theme'));
    }
}
