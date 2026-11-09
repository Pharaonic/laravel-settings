<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\InvalidSettingKeyException;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Models\Setting;

test('set value returns the persisted row', function () {
    $setting = settings('checkout')->setValue('minimum', 100);

    $this->assertTrue($setting->exists);
    $this->assertIsInt($setting->id);
    $this->assertSame('checkout', $setting->identifier);
    $this->assertSame('minimum', $setting->key);
    $this->assertSame(100, $setting->value);
    $this->assertNotNull($setting->created_at);
    $this->assertTrue($setting->is(Setting::for('checkout')->where('key', 'minimum')->first()));
});

test('a key has one row', function () {
    settings()->setValue('title', 'One');
    $setting = settings()->setValue('title', 'Two');
    settings()->setValue('title', 'Two');

    $this->assertSame(1, Setting::query()->count());
    $this->assertSame('Two', $setting->value);
    $this->assertSame('Two', settings()->getValue('title'));
});

test('the database refuses a duplicate row', function () {
    settings()->setValue('title', 'One');

    $this->expectException(QueryException::class);

    DB::table('settings')->insert([
        'identifier' => Setting::GLOBAL,
        'key' => 'title',
        'type' => 'string',
        'nullable' => false,
        'value' => '"Two"',
    ]);
});

test('keys that differ only by case are separate rows', function () {
    settings()->setValue('Theme', 'upper');
    settings()->setValue('theme', 'lower');

    $this->assertSame('upper', settings()->getValue('Theme'));
    $this->assertSame('lower', settings()->getValue('theme'));
    $this->assertSame(2, Setting::query()->count());
});

test('forget value only touches its own scope', function () {
    settings()->setValue('theme', 'global');
    settings('tenant')->setValue('theme', 'tenant');

    $this->assertTrue(settings('tenant')->forgetValue('theme'));
    $this->assertFalse(settings('tenant')->forgetValue('theme'));
    $this->assertFalse(settings('tenant')->hasValue('theme'));
    $this->assertSame('global', settings()->getValue('theme'));
});

test('set many stores several values', function () {
    settings()->setMany(['a' => 1, 'b' => 'two', 'c' => [3]]);

    $this->assertSame(['a' => 1, 'b' => 'two', 'c' => [3]], settings()->allValues());
});

test('set many stores everything or nothing', function () {
    settings()->setValue('count', 1);

    $this->assertThrowsException(fn () => settings()->setMany(['fresh' => 'x', 'count' => 'not an int']), SettingTypeMismatchException::class);
    $this->assertFalse(settings()->hasValue('fresh'));
    $this->assertSame(1, settings()->getValue('count'));

    config(['pharaonic.settings.definitions' => ['limit' => ['type' => 'integer', 'rules' => ['max:5']]]]);

    $this->assertThrowsException(fn () => settings()->setMany(['fresh' => 'x', 'limit' => 9]), ValidationException::class);
    $this->assertFalse(settings()->hasValue('fresh'));
});

test('set many refuses integer keys', function () {
    $this->assertThrowsException(fn () => settings()->setMany(['a', 'b']), InvalidSettingKeyException::class);
    $this->assertThrowsException(fn () => settings()->setMany(['ok' => 1, 5 => 2]), InvalidSettingKeyException::class);
    $this->assertSame(0, Setting::query()->count());
});

test('writes roll back with the surrounding transaction', function () {
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
});

test('retype value changes the type and converts the value', function () {
    settings()->setValue('limit', '20');

    $setting = settings()->retypeValue('limit', SettingType::INTEGER);

    $this->assertSame(SettingType::INTEGER, $setting->type);
    $this->assertSame(20, $setting->value);
    $this->assertSame(20, settings()->getValue('limit'));

    $setting = settings()->retypeValue('limit', SettingType::STRING, true);

    $this->assertSame('20', $setting->value);
    $this->assertTrue($setting->nullable);
    $this->assertNull(settings()->setValue('limit', null)->value);
});

test('a retype that cannot convert leaves the row unchanged', function () {
    settings()->setValue('label', 'twenty');

    $this->assertThrowsException(fn () => settings()->retypeValue('label', SettingType::INTEGER), SettingTypeMismatchException::class);
    $this->assertSame(SettingType::STRING, Setting::for()->where('key', 'label')->value('type'));
    $this->assertSame('twenty', settings()->getValue('label'));

    settings()->setValue('optional', null);
    settings()->setValue('optional', 'x');
    settings()->setValue('optional', null);

    $this->assertThrowsException(fn () => settings()->retypeValue('optional', SettingType::STRING, false), SettingTypeMismatchException::class);
});

test('retype refuses a missing setting and its definition', function () {
    $this->assertThrowsException(fn () => settings()->retypeValue('missing', SettingType::STRING), InvalidArgumentException::class);

    settings()->setValue('pinned', 1);
    config(['pharaonic.settings.definitions' => ['pinned' => ['type' => 'integer']]]);

    $this->assertThrowsException(fn () => settings()->retypeValue('pinned', SettingType::STRING), SettingDefinitionException::class);
    $this->assertSame(1, settings()->getValue('pinned'));
});

test('a native save cannot change the metadata', function () {
    $setting = settings()->setValue('count', 1);
    $setting->type = SettingType::STRING;

    $this->assertThrowsException(fn () => $setting->save(), SettingTypeMismatchException::class);

    $setting = Setting::for()->where('key', 'count')->firstOrFail();
    $setting->nullable = true;

    $this->assertThrowsException(fn () => $setting->save(), SettingTypeMismatchException::class);
});

test('a model delete forgets the cache', function () {
    $setting = settings()->setValue('theme', 'dark');
    settings()->getValue('theme');

    $setting->delete();

    $this->assertFalse(settings()->hasValue('theme'));
});
