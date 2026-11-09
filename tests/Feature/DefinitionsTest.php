<?php

use Illuminate\Validation\ValidationException;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;

beforeEach(function () {
    config(['pharaonic.settings.definitions' => [
        'checkout.minimum_order' => [
            'type' => 'float',
            'nullable' => false,
            'default' => 0.0,
            'casting' => 'safe',
            'rules' => ['numeric', 'min:0'],
        ],
        'notifications.email' => [
            'type' => 'boolean',
            'default' => true,
            'casting' => 'strict',
        ],
        'branding.logo' => [
            'type' => 'string',
            'nullable' => true,
            'rules' => 'max:10',
        ],
    ]]);
});

dataset('definitions_invalidDefinitions', function () {
    return [
        'no type' => [['nullable' => true]],
        'unknown type' => [['type' => 'number']],
        'nullable not bool' => [['type' => 'string', 'nullable' => 'yes']],
        'bad casting' => [['type' => 'string', 'casting' => 'loose']],
        'default of another type' => [['type' => 'integer', 'default' => '1']],
        'null default, not nullable' => [['type' => 'integer', 'default' => null]],
        'encrypted' => [['type' => 'string', 'encrypted' => true]],
        'unknown option' => [['type' => 'string', 'label' => 'x']],
        'bad rules' => [['type' => 'string', 'rules' => 5]],
    ];
});

test('the definition default is returned without being stored', function () {
    $this->assertSame(0.0, settings()->getValue('checkout.minimum_order'));
    $this->assertTrue(settings()->getValue('notifications.email'));
    $this->assertFalse(settings()->hasValue('checkout.minimum_order'));
    $this->assertSame(0, Setting::query()->count());
});

test('an explicit default wins over the definition default', function () {
    $calls = 0;

    $this->assertSame(5.0, settings()->getValue('checkout.minimum_order', 5.0));
    $this->assertFalse(settings()->getValue('notifications.email', function () use (&$calls) {
        $calls++;

        return false;
    }));
    $this->assertSame(1, $calls);
});

test('a stored null wins over the default', function () {
    settings()->setValue('branding.logo', null);

    $this->assertNull(settings()->getValue('branding.logo', 'fallback'));
});

test('the definition type applies in every scope', function () {
    $company = Company::create();

    $this->assertSame('float', settings()->setValue('checkout.minimum_order', 10.5)->type);
    $this->assertSame(12.5, settings('checkout')->setValue('checkout.minimum_order', '12.5')->value);
    $this->assertSame(3.0, settings($company)->setValue('checkout.minimum_order', 3)->value);
    $this->assertThrowsException(fn () => settings($company)->setValue('notifications.email', 'true'), SettingTypeMismatchException::class);
    $this->assertThrowsException(fn () => settings('fresh')->setValue('checkout.minimum_order', 1.0, 'integer'), SettingDefinitionException::class);
    $this->assertThrowsException(fn () => settings()->setValue('notifications.email', null), SettingTypeMismatchException::class);
});

test('validation rules run after the type check', function () {
    $this->assertThrowsException(fn () => settings()->setValue('checkout.minimum_order', -1.0), ValidationException::class);
    $this->assertThrowsException(fn () => settings()->setValue('branding.logo', 'far too long a logo'), ValidationException::class);
    $this->assertFalse(settings()->hasValue('checkout.minimum_order'));

    try {
        settings()->setValue('checkout.minimum_order', -1.0);
        $this->fail('The value was not refused.');
    } catch (ValidationException $exception) {
        $this->assertArrayHasKey('value', $exception->errors());
        $this->assertStringContainsString('checkout.minimum_order', implode(' ', $exception->errors()['value']));
    }
});

test('size rules compare numbers as numbers', function () {
    config(['pharaonic.settings.definitions' => ['limit' => ['type' => 'integer', 'rules' => ['max:5']]]]);

    $this->assertSame(5, settings()->setValue('limit', 5)->value);
    $this->assertThrowsException(fn () => settings()->setValue('limit', 9), ValidationException::class);
});

test('the rules are skipped for a stored null', function () {
    $this->assertNull(settings()->setValue('branding.logo', null)->value);
});

test('a stored row that contradicts its definition is refused until retyped', function () {
    config(['pharaonic.settings.definitions' => []]);
    settings()->setValue('checkout.minimum_order', 'ten');
    config(['pharaonic.settings.definitions' => ['checkout.minimum_order' => ['type' => 'float']]]);

    $this->assertThrowsException(fn () => settings()->getValue('checkout.minimum_order'), SettingDefinitionException::class);
    $this->assertThrowsException(fn () => settings()->setValue('checkout.minimum_order', 10.0), SettingDefinitionException::class);
    $this->assertThrowsException(fn () => settings()->retypeValue('checkout.minimum_order', 'float'), SettingTypeMismatchException::class);

    Setting::query()->toBase()->where('key', 'checkout.minimum_order')->update(['value' => '"10.5"']);

    $this->assertSame(10.5, settings()->retypeValue('checkout.minimum_order', 'float', false)->value);
    $this->assertSame(10.5, settings()->getValue('checkout.minimum_order'));
});

test('invalid definitions are refused', function (array $definition) {
    config(['pharaonic.settings.definitions' => ['broken' => $definition]]);

    $this->expectException(SettingDefinitionException::class);

    settings()->getValue('broken');
})->with('definitions_invalidDefinitions');

test('an invalid global casting policy is refused', function () {
    config(['pharaonic.settings.types.casting' => 'loose']);

    $this->expectException(SettingDefinitionException::class);

    settings()->setValue('x', 1);
});

test('definitions survive config cache', function () {
    $exported = var_export(config('pharaonic.settings.definitions'), true);

    $this->assertStringNotContainsString('Closure', $exported);

    // A cached configuration is a var_export()ed array, read back as is.
    config(['pharaonic.settings.definitions' => eval('return '.$exported.';')]);

    $this->assertSame(0.0, settings()->getValue('checkout.minimum_order'));
});
