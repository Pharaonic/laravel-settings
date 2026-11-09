<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use InvalidArgumentException;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\CorruptSettingValueException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Exceptions\UnsupportedSettingValueException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use stdClass;

final class TypesTest extends TestCase
{
    /**
     * @dataProvider inferred
     */
    public function test_a_new_setting_takes_the_type_of_its_value(mixed $value, string $type): void
    {
        $setting = settings()->setValue('key', $value);

        $this->assertSame($type, $setting->type);
        $this->assertFalse($setting->nullable);
        $this->assertSame($value, settings()->getValue('key'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function inferred(): array
    {
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
    }

    public function test_arrays_round_trip_as_arrays_never_objects(): void
    {
        settings()->setValue('list', [1, 2, 3]);
        settings()->setValue('map', ['x' => ['y' => 'z']]);
        settings()->setValue('sparse', [2 => 'b', 0 => 'a']);

        $this->assertSame([1, 2, 3], settings()->getValue('list'));
        $this->assertSame(['x' => ['y' => 'z']], settings()->getValue('map'));
        $this->assertSame([2 => 'b', 0 => 'a'], settings()->getValue('sparse'));
    }

    public function test_strings_that_look_like_json_stay_strings(): void
    {
        settings()->setValue('a', '{"x":1}');
        settings()->setValue('b', '["x"]');
        settings()->setValue('c', 'true');
        settings()->setValue('d', '20');

        $this->assertSame('{"x":1}', settings()->getValue('a'));
        $this->assertSame('["x"]', settings()->getValue('b'));
        $this->assertSame('true', settings()->getValue('c'));
        $this->assertSame('20', settings()->getValue('d'));
    }

    public function test_a_first_null_stays_untyped_until_the_first_value_fixes_the_type(): void
    {
        $setting = settings()->setValue('unknown', null);

        $this->assertNull($setting->type);
        $this->assertTrue($setting->nullable);
        $this->assertSame('null', $setting->getAttributes()['value']);
        $this->assertTrue(settings()->hasValue('unknown'));
        $this->assertNull(settings()->getValue('unknown', 'default'));

        $setting = settings()->setValue('unknown', 5);

        $this->assertSame('integer', $setting->type);
        $this->assertTrue($setting->nullable);
        $this->assertThrowsException(fn () => settings()->setValue('unknown', 'five'), SettingTypeMismatchException::class);

        settings()->setValue('unknown', null);

        $this->assertNull(settings()->getValue('unknown', 'default'));
        $this->assertSame('integer', Setting::for()->where('key', 'unknown')->value('type'));
    }

    public function test_a_write_never_changes_the_type(): void
    {
        settings()->setValue('count', 20);

        $this->assertThrowsException(fn () => settings()->setValue('count', '20'), SettingTypeMismatchException::class);
        $this->assertThrowsException(fn () => settings()->setValue('count', 20.0), SettingTypeMismatchException::class);
        $this->assertThrowsException(fn () => settings()->setValue('count', true), SettingTypeMismatchException::class);
        $this->assertThrowsException(fn () => settings()->setValue('count', 1, SettingType::FLOAT), SettingTypeMismatchException::class);
        $this->assertSame(20, settings()->getValue('count'));
        $this->assertSame(21, settings()->setValue('count', 21, SettingType::INTEGER)->value);
    }

    public function test_null_is_refused_for_a_setting_that_is_not_nullable(): void
    {
        settings()->setValue('count', 20);

        $this->assertThrowsException(fn () => settings()->setValue('count', null), SettingTypeMismatchException::class);
        $this->assertThrowsException(fn () => settings()->setValue('new', null, null, false), SettingTypeMismatchException::class);
        $this->assertThrowsException(fn () => settings()->setValue('count', 21, null, true), SettingTypeMismatchException::class);
    }

    public function test_a_new_setting_takes_an_explicit_type_and_nullability(): void
    {
        $setting = settings()->setValue('limit', null, SettingType::INTEGER);

        $this->assertSame('integer', $setting->type);
        $this->assertTrue($setting->nullable);
        $this->assertThrowsException(fn () => settings()->setValue('limit', 'x'), SettingTypeMismatchException::class);

        $setting = settings()->setValue('ratio', 1.5, SettingType::FLOAT, true);

        $this->assertTrue($setting->nullable);
        $this->assertNull(settings()->setValue('ratio', null)->value);
    }

    public function test_an_unknown_type_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        settings()->setValue('x', 1, 'number');
    }

    public function test_the_strict_policy_refuses_a_mismatched_explicit_type(): void
    {
        $this->assertThrowsException(fn () => settings()->setValue('max_items', '20', SettingType::INTEGER), SettingTypeMismatchException::class);
        $this->assertFalse(settings()->hasValue('max_items'));
    }

    public function test_the_safe_policy_converts_a_mismatched_explicit_type(): void
    {
        config(['pharaonic.settings.types.casting' => 'safe']);

        $setting = settings()->setValue('max_items', '20', SettingType::INTEGER);

        $this->assertSame('integer', $setting->type);
        $this->assertSame(20, settings()->getValue('max_items'));
        $this->assertSame(30, settings()->setValue('max_items', '30')->value);
        $this->assertThrowsException(fn () => settings()->setValue('max_items', '20.5'), SettingTypeMismatchException::class);

        settings()->setValue('enabled', 'false', SettingType::BOOLEAN);

        $this->assertFalse(settings()->getValue('enabled'));
    }

    /**
     * @dataProvider unsupported
     */
    public function test_unsupported_values_are_refused_naming_the_key_but_not_the_value(mixed $value, string $type): void
    {
        try {
            settings('tenant')->setValue('secret', $value);
            $this->fail('No exception was thrown.');
        } catch (UnsupportedSettingValueException $exception) {
            $this->assertStringContainsString('[secret]', $exception->getMessage());
            $this->assertStringContainsString('[tenant]', $exception->getMessage());
            $this->assertStringContainsString($type, $exception->getMessage());
        }

        $this->assertFalse(settings('tenant')->hasValue('secret'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unsupported(): array
    {
        return [
            'object' => [new stdClass, 'stdClass'],
            'closure' => [function () {
                return null;
            }, 'Closure'],
            'INF' => [INF, 'non-finite float'],
            'NAN' => [NAN, 'non-finite float'],
            'invalid UTF-8' => ["\xB1\x31", 'non-UTF-8 string'],
            'nested object' => [['a' => [new stdClass]], 'stdClass'],
            'nested INF' => [[1, [INF]], 'non-finite float'],
            'invalid UTF-8 key' => [["\xB1" => 1], 'non-UTF-8 array key'],
        ];
    }

    public function test_arrays_nested_too_deeply_are_refused(): void
    {
        $value = 'leaf';

        for ($i = 0; $i < 70; $i++) {
            $value = [$value];
        }

        $this->expectException(UnsupportedSettingValueException::class);

        settings()->setValue('deep', $value);
    }

    public function test_stored_json_the_package_did_not_write_is_reported(): void
    {
        settings()->setValue('broken', 'x');
        Setting::query()->toBase()->where('key', 'broken')->update(['value' => '{not json']);

        $this->expectException(CorruptSettingValueException::class);

        settings()->getValue('broken');
    }
}
