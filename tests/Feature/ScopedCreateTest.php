<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class ScopedCreateTest extends TestCase
{
    public function test_create_on_a_scope_stores_in_that_scope(): void
    {
        $company = Company::create();
        $identifier = (string) Setting::for($company)->getIdentifier();

        $setting = $company->settings()->create(['key' => 'theme', 'value' => 'dark']);

        $this->assertSame($identifier, $setting->identifier);
        $this->assertSame('dark', $company->getSetting('theme'));
        $this->assertFalse(settings()->hasValue('theme'));
    }

    public function test_every_eloquent_create_path_keeps_the_scope(): void
    {
        $scope = settings('checkout');

        $made = settings('checkout')->make(['key' => 'made', 'value' => 1]);
        $made->save();
        settings('checkout')->firstOrCreate(['key' => 'first'], ['value' => 2]);
        settings('checkout')->updateOrCreate(['key' => 'updated'], ['value' => 3]);
        settings('checkout')->updateOrCreate(['key' => 'updated'], ['value' => 4]);
        settings('checkout')->firstOrNew(['key' => 'new'], ['value' => 5])->save();
        settings('checkout')->forceCreate(['key' => 'forced', 'value' => 6]);

        $this->assertSame(['first' => 2, 'forced' => 6, 'made' => 1, 'new' => 5, 'updated' => 4], $scope->allValues());
        $this->assertSame(0, Setting::query()->where('identifier', '!=', 'checkout')->count());
    }

    public function test_first_or_create_finds_the_scoped_row(): void
    {
        settings()->setValue('theme', 'global');
        settings('tenant')->setValue('theme', 'tenant');

        $setting = settings('tenant')->firstOrCreate(['key' => 'theme'], ['value' => 'new']);

        $this->assertSame('tenant', $setting->value);
        $this->assertSame(2, Setting::query()->count());
    }

    public function test_a_scoped_create_refuses_another_identifier(): void
    {
        try {
            settings('tenant-a')->create(['identifier' => 'tenant-b', 'key' => 'theme', 'value' => 'x']);
            $this->fail('The other identifier was accepted.');
        } catch (InvalidIdentifierException $exception) {
            $this->assertSame(0, Setting::query()->count());
        }

        $this->assertThrowsException(fn () => settings('tenant-a')->forceCreate(['identifier' => Setting::GLOBAL, 'key' => 'theme', 'value' => 'x']), InvalidIdentifierException::class);
        $this->assertThrowsException(fn () => settings('tenant-a')->updateOrCreate(['key' => 'theme'], ['identifier' => 'tenant-b', 'value' => 'x']), InvalidIdentifierException::class);
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_a_made_model_cannot_be_moved_to_another_scope_before_saving(): void
    {
        $setting = settings('tenant-a')->make(['key' => 'theme', 'value' => 'x']);
        $setting->identifier = 'tenant-b';

        $this->assertThrowsException(fn () => $setting->save(), InvalidIdentifierException::class);
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_the_same_identifier_is_accepted(): void
    {
        $setting = settings('tenant')->create(['identifier' => 'tenant', 'key' => 'theme', 'value' => 'x']);

        $this->assertSame('tenant', $setting->identifier);
    }

    public function test_an_unscoped_create_takes_the_identifier_it_is_given(): void
    {
        Setting::query()->create(['identifier' => 'named', 'key' => 'a', 'value' => 1]);
        Setting::create(['key' => 'b', 'value' => 2]);

        $this->assertSame(1, settings('named')->getValue('a'));
        $this->assertSame(2, settings()->getValue('b'));
    }
}
