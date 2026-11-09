<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Validation\ValidationException;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class DefinitionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_the_definition_default_is_returned_without_being_stored(): void
    {
        $this->assertSame(0.0, settings()->getValue('checkout.minimum_order'));
        $this->assertTrue(settings()->getValue('notifications.email'));
        $this->assertFalse(settings()->hasValue('checkout.minimum_order'));
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_an_explicit_default_wins_over_the_definition_default(): void
    {
        $calls = 0;

        $this->assertSame(5.0, settings()->getValue('checkout.minimum_order', 5.0));
        $this->assertFalse(settings()->getValue('notifications.email', function () use (&$calls) {
            $calls++;

            return false;
        }));
        $this->assertSame(1, $calls);
    }

    public function test_a_stored_null_wins_over_the_default(): void
    {
        settings()->setValue('branding.logo', null);

        $this->assertNull(settings()->getValue('branding.logo', 'fallback'));
    }

    public function test_the_definition_type_applies_in_every_scope(): void
    {
        $company = Company::create();

        $this->assertSame('float', settings()->setValue('checkout.minimum_order', 10.5)->type);
        $this->assertSame(12.5, settings('checkout')->setValue('checkout.minimum_order', '12.5')->value);
        $this->assertSame(3.0, settings($company)->setValue('checkout.minimum_order', 3)->value);
        $this->assertThrowsException(fn () => settings($company)->setValue('notifications.email', 'true'), SettingTypeMismatchException::class);
        $this->assertThrowsException(fn () => settings('fresh')->setValue('checkout.minimum_order', 1.0, 'integer'), SettingDefinitionException::class);
        $this->assertThrowsException(fn () => settings()->setValue('notifications.email', null), SettingTypeMismatchException::class);
    }

    public function test_validation_rules_run_after_the_type_check(): void
    {
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
    }

    public function test_size_rules_compare_numbers_as_numbers(): void
    {
        config(['pharaonic.settings.definitions' => ['limit' => ['type' => 'integer', 'rules' => ['max:5']]]]);

        $this->assertSame(5, settings()->setValue('limit', 5)->value);
        $this->assertThrowsException(fn () => settings()->setValue('limit', 9), ValidationException::class);
    }

    public function test_the_rules_are_skipped_for_a_stored_null(): void
    {
        $this->assertNull(settings()->setValue('branding.logo', null)->value);
    }

    public function test_a_stored_row_that_contradicts_its_definition_is_refused_until_retyped(): void
    {
        config(['pharaonic.settings.definitions' => []]);
        settings()->setValue('checkout.minimum_order', 'ten');
        config(['pharaonic.settings.definitions' => ['checkout.minimum_order' => ['type' => 'float']]]);

        $this->assertThrowsException(fn () => settings()->getValue('checkout.minimum_order'), SettingDefinitionException::class);
        $this->assertThrowsException(fn () => settings()->setValue('checkout.minimum_order', 10.0), SettingDefinitionException::class);
        $this->assertThrowsException(fn () => settings()->retypeValue('checkout.minimum_order', 'float'), SettingTypeMismatchException::class);

        Setting::query()->toBase()->where('key', 'checkout.minimum_order')->update(['value' => '"10.5"']);

        $this->assertSame(10.5, settings()->retypeValue('checkout.minimum_order', 'float', false)->value);
        $this->assertSame(10.5, settings()->getValue('checkout.minimum_order'));
    }

    /**
     * @dataProvider invalidDefinitions
     *
     * @param  array<string, mixed>  $definition
     */
    public function test_invalid_definitions_are_refused(array $definition): void
    {
        config(['pharaonic.settings.definitions' => ['broken' => $definition]]);

        $this->expectException(SettingDefinitionException::class);

        settings()->getValue('broken');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidDefinitions(): array
    {
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
    }

    public function test_an_invalid_global_casting_policy_is_refused(): void
    {
        config(['pharaonic.settings.types.casting' => 'loose']);

        $this->expectException(SettingDefinitionException::class);

        settings()->setValue('x', 1);
    }

    public function test_definitions_survive_config_cache(): void
    {
        $exported = var_export(config('pharaonic.settings.definitions'), true);

        $this->assertStringNotContainsString('Closure', $exported);

        // A cached configuration is a var_export()ed array, read back as is.
        config(['pharaonic.settings.definitions' => eval('return '.$exported.';')]);

        $this->assertSame(0.0, settings()->getValue('checkout.minimum_order'));
    }
}
