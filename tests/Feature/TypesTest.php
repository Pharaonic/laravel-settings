<?php

use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\CorruptSettingValueException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Exceptions\UnsupportedSettingValueException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;

dataset('types_inferred', function () {
    return [
        'boolean' => [true, 'boolean'],
        'false' => [false, 'boolean'],
        'integer' => [20, 'integer'],
        'zero' => [0, 'integer'],
        'float' => [19.99, 'float'],
        'whole float' => [2.0, 'float'],
        'string' => ['Pharaonic', 'string'],
        'empty string' => ['', 'string'],
        'list' => [['ar', 'en'], 'array'],
        'empty array' => [[], 'array'],
        'map' => [['a' => 1, 'b' => ['c' => 2.5, 'd' => null]], 'array'],
    ];
});

dataset('types_unsupported', function () {
    return [
        'object' => [new stdClass, 'stdClass'],
        // Pest calls a closure in a dataset for its value, so the closure under
        // test is the value of another one.
        'closure' => [function () {
            return function () {
                return null;
            };
        }, 'Closure'],
        'INF' => [INF, 'non-finite float'],
        'NAN' => [NAN, 'non-finite float'],
        'invalid UTF-8' => ["\xB1\x31", 'non-UTF-8 string'],
        'nested object' => [['a' => [new stdClass]], 'stdClass'],
        'nested INF' => [[1, [INF]], 'non-finite float'],
        'invalid UTF-8 key' => [["\xB1" => 1], 'non-UTF-8 array key'],
    ];
});

test('a new setting takes the type of its value', function (mixed $value, string $type) {
    $setting = settings()->setValue('key', $value);

    $this->assertSame($type, $setting->type?->value);
    $this->assertFalse($setting->nullable);
    $this->assertSame($value, settings()->getValue('key'));
})->with('types_inferred');

test('arrays round trip as arrays never objects', function () {
    settings()->setValue('list', [1, 2, 3]);
    settings()->setValue('map', ['x' => ['y' => 'z']]);
    settings()->setValue('sparse', [2 => 'b', 0 => 'a']);

    $this->assertSame([1, 2, 3], settings()->getValue('list'));
    $this->assertSame(['x' => ['y' => 'z']], settings()->getValue('map'));
    $this->assertSame([2 => 'b', 0 => 'a'], settings()->getValue('sparse'));
});

test('strings that look like json stay strings', function () {
    settings()->setValue('a', '{"x":1}');
    settings()->setValue('b', '["x"]');
    settings()->setValue('c', 'true');
    settings()->setValue('d', '20');

    $this->assertSame('{"x":1}', settings()->getValue('a'));
    $this->assertSame('["x"]', settings()->getValue('b'));
    $this->assertSame('true', settings()->getValue('c'));
    $this->assertSame('20', settings()->getValue('d'));
});

test('a first null stays untyped until the first value fixes the type', function () {
    $setting = settings()->setValue('unknown', null);

    $this->assertNull($setting->type);
    $this->assertTrue($setting->nullable);
    $this->assertSame('null', $setting->getAttributes()['value']);
    $this->assertTrue(settings()->hasValue('unknown'));
    $this->assertNull(settings()->getValue('unknown', 'default'));

    $setting = settings()->setValue('unknown', 5);

    $this->assertSame(SettingType::INTEGER, $setting->type);
    $this->assertTrue($setting->nullable);
    $this->assertThrowsException(fn () => settings()->setValue('unknown', 'five'), SettingTypeMismatchException::class);

    settings()->setValue('unknown', null);

    $this->assertNull(settings()->getValue('unknown', 'default'));
    $this->assertSame(SettingType::INTEGER, Setting::for()->where('key', 'unknown')->value('type'));
});

test('a write never changes the type', function () {
    settings()->setValue('count', 20);

    $this->assertThrowsException(fn () => settings()->setValue('count', '20'), SettingTypeMismatchException::class);
    $this->assertThrowsException(fn () => settings()->setValue('count', 20.0), SettingTypeMismatchException::class);
    $this->assertThrowsException(fn () => settings()->setValue('count', true), SettingTypeMismatchException::class);
    $this->assertThrowsException(fn () => settings()->setValue('count', 1, SettingType::FLOAT), SettingTypeMismatchException::class);
    $this->assertSame(20, settings()->getValue('count'));
    $this->assertSame(21, settings()->setValue('count', 21, SettingType::INTEGER)->value);
});

test('null is refused for a setting that is not nullable', function () {
    settings()->setValue('count', 20);

    $this->assertThrowsException(fn () => settings()->setValue('count', null), SettingTypeMismatchException::class);
    $this->assertThrowsException(fn () => settings()->setValue('new', null, null, false), SettingTypeMismatchException::class);
    $this->assertThrowsException(fn () => settings()->setValue('count', 21, null, true), SettingTypeMismatchException::class);
});

test('a new setting takes an explicit type and nullability', function () {
    $setting = settings()->setValue('limit', null, SettingType::INTEGER);

    $this->assertSame(SettingType::INTEGER, $setting->type);
    $this->assertTrue($setting->nullable);
    $this->assertThrowsException(fn () => settings()->setValue('limit', 'x'), SettingTypeMismatchException::class);

    $setting = settings()->setValue('ratio', 1.5, SettingType::FLOAT, true);

    $this->assertTrue($setting->nullable);
    $this->assertNull(settings()->setValue('ratio', null)->value);
});

test('an unknown type name is refused', function () {
    $this->expectException(InvalidArgumentException::class);

    settings()->setValue('x', 1, 'number');
});

test('the strict policy refuses a mismatched explicit type', function () {
    $this->assertThrowsException(fn () => settings()->setValue('max_items', '20', SettingType::INTEGER), SettingTypeMismatchException::class);
    $this->assertFalse(settings()->hasValue('max_items'));
});

test('the safe policy converts a mismatched explicit type', function () {
    config(['pharaonic.settings.types.casting' => 'safe']);

    $setting = settings()->setValue('max_items', '20', SettingType::INTEGER);

    $this->assertSame(SettingType::INTEGER, $setting->type);
    $this->assertSame(20, settings()->getValue('max_items'));
    $this->assertSame(30, settings()->setValue('max_items', '30')->value);
    $this->assertThrowsException(fn () => settings()->setValue('max_items', '20.5'), SettingTypeMismatchException::class);

    settings()->setValue('enabled', 'false', SettingType::BOOLEAN);

    $this->assertFalse(settings()->getValue('enabled'));
});

test('unsupported values are refused naming the key but not the value', function (mixed $value, string $type) {
    try {
        settings('tenant')->setValue('secret', $value);
        $this->fail('No exception was thrown.');
    } catch (UnsupportedSettingValueException $exception) {
        $this->assertStringContainsString('[secret]', $exception->getMessage());
        $this->assertStringContainsString('[tenant]', $exception->getMessage());
        $this->assertStringContainsString($type, $exception->getMessage());
    }

    $this->assertFalse(settings('tenant')->hasValue('secret'));
})->with('types_unsupported');

test('arrays nested too deeply are refused', function () {
    $value = 'leaf';

    for ($i = 0; $i < 70; $i++) {
        $value = [$value];
    }

    $this->expectException(UnsupportedSettingValueException::class);

    settings()->setValue('deep', $value);
});

test('stored json the package did not write is reported', function () {
    settings()->setValue('broken', 'x');
    Setting::query()->toBase()->where('key', 'broken')->update(['value' => '{not json']);

    $this->expectException(CorruptSettingValueException::class);

    settings()->getValue('broken');
});

test('the model returns the type as an enum', function () {
    $setting = settings()->setValue('count', 1);

    $this->assertInstanceOf(SettingType::class, $setting->type);
    $this->assertSame(SettingType::INTEGER, Setting::for()->where('key', 'count')->firstOrFail()->type);
    $this->assertSame('integer', $setting->getAttributes()['type']);
    $this->assertSame('integer', settings()->explainValue('count')['type']);
    $this->assertSame(1, Setting::query()->where('type', SettingType::INTEGER)->count());
});

test('methods take a type as an enum or its value', function () {
    $this->assertSame(SettingType::FLOAT, settings()->setValue('a', 1.5, SettingType::FLOAT)->type);
    $this->assertSame(SettingType::FLOAT, settings()->setValue('b', 1.5, 'float')->type);
    $this->assertSame(SettingType::STRING, settings()->retypeValue('a', 'string')->type);
    $this->assertSame(SettingType::STRING, settings()->retypeValue('b', SettingType::STRING)->type);
    $this->assertSame(SettingType::ARRAY, Company::create()->setSetting('list', [], SettingType::ARRAY)->type);
});

test('definitions and the casting option take enum cases', function () {
    config(['pharaonic.settings.types.casting' => CastingPolicy::SAFE]);
    config(['pharaonic.settings.definitions' => [
        'limit' => ['type' => SettingType::INTEGER, 'casting' => CastingPolicy::STRICT],
        'ratio' => ['type' => SettingType::FLOAT],
    ]]);

    $this->assertSame(1.5, settings()->setValue('ratio', '1.5')->value);
    $this->assertThrowsException(fn () => settings()->setValue('limit', '20'), SettingTypeMismatchException::class);
    $this->assertSame(SettingType::INTEGER, settings()->setValue('limit', 20)->type);
});
