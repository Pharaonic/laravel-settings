<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Exceptions\InvalidSettingKeyException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\Fixtures\UuidModel;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class IdentifierTest extends TestCase
{
    public function test_omitted_null_and_global_are_the_one_global_scope(): void
    {
        Setting::for()->setValue('site.name', 'Pharaonic');

        $this->assertSame('__global__', Setting::GLOBAL);
        $this->assertSame(Setting::GLOBAL, Setting::for()->getIdentifier());
        $this->assertSame('Pharaonic', Setting::for(null)->getValue('site.name'));
        $this->assertSame('Pharaonic', Setting::for(Setting::GLOBAL)->getValue('site.name'));
        $this->assertSame('Pharaonic', Setting::for('__global__')->getValue('site.name'));
        $this->assertSame('Pharaonic', settings()->getValue('site.name'));
        $this->assertSame('Pharaonic', settings(null)->getValue('site.name'));
        $this->assertSame(1, Setting::query()->where('identifier', Setting::GLOBAL)->count());
        $this->assertSame(0, Setting::query()->whereNull('identifier')->count());
    }

    public function test_named_scopes_are_apart_from_each_other_and_from_the_global_scope(): void
    {
        Setting::for()->setValue('minimum', 1);
        Setting::for('checkout')->setValue('minimum', 100);
        Setting::for('Checkout')->setValue('minimum', 200);

        $this->assertSame(1, Setting::for()->getValue('minimum'));
        $this->assertSame(100, Setting::for('checkout')->getValue('minimum'));
        $this->assertSame(200, Setting::for('Checkout')->getValue('minimum'));
        $this->assertFalse(Setting::for('global')->hasValue('minimum'));
    }

    public function test_a_model_is_its_morph_class_and_key_the_same_as_the_string(): void
    {
        $company = Company::create();
        Setting::for($company)->setValue('timezone', 'Africa/Cairo');
        $identifier = Company::class.':'.$company->getKey();

        $this->assertSame($identifier, Setting::for($company)->getIdentifier());
        $this->assertSame('Africa/Cairo', Setting::for($identifier)->getValue('timezone'));
        $this->assertSame('Africa/Cairo', settings($company)->getValue('timezone'));
        $this->assertSame('Africa/Cairo', $company->settings()->getValue('timezone'));
    }

    public function test_it_honors_the_morph_map(): void
    {
        Relation::morphMap(['company' => Company::class]);
        $company = Company::create();

        Setting::for($company)->setValue('theme', 'dark');

        $this->assertSame('company:'.$company->getKey(), Setting::for($company)->getIdentifier());
        $this->assertSame('dark', Setting::for('company:'.$company->getKey())->getValue('theme'));
    }

    public function test_it_accepts_string_and_zero_keys(): void
    {
        $uuid = UuidModel::create(['id' => '9b2f0c1e-0000-4000-8000-000000000000']);
        $zero = new Company;
        $zero->id = 0;
        $zero->exists = true;
        $stringZero = new UuidModel(['id' => '0']);
        $stringZero->exists = true;

        $this->assertSame(UuidModel::class.':9b2f0c1e-0000-4000-8000-000000000000', Setting::for($uuid)->getIdentifier());
        $this->assertSame(Company::class.':0', Setting::for($zero)->getIdentifier());
        $this->assertSame(UuidModel::class.':0', Setting::for($stringZero)->getIdentifier());
    }

    public function test_it_refuses_an_unsaved_model(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        Setting::for(new Company);
    }

    public function test_it_refuses_a_model_without_a_key(): void
    {
        $keyless = new Company;
        $keyless->exists = true;

        $this->expectException(InvalidIdentifierException::class);

        Setting::for($keyless);
    }

    public function test_it_refuses_a_model_with_an_empty_key(): void
    {
        $empty = new UuidModel(['id' => '']);
        $empty->exists = true;

        $this->expectException(InvalidIdentifierException::class);

        Setting::for($empty);
    }

    /**
     * @dataProvider invalidIdentifiers
     */
    public function test_it_refuses_invalid_identifiers(string $identifier): void
    {
        $this->expectException(InvalidIdentifierException::class);

        Setting::for($identifier);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdentifiers(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 192)],
            'invalid UTF-8' => ["\xB1\x31"],
        ];
    }

    public function test_it_accepts_an_identifier_of_the_maximum_length(): void
    {
        $identifier = str_repeat('é', 191);

        Setting::for($identifier)->setValue('x', 1);

        $this->assertSame(1, Setting::for($identifier)->getValue('x'));
    }

    /**
     * @dataProvider invalidKeys
     */
    public function test_it_refuses_invalid_keys_on_write(string $key): void
    {
        $this->expectException(InvalidSettingKeyException::class);

        Setting::for()->setValue($key, 1);
    }

    /**
     * @dataProvider invalidKeys
     */
    public function test_it_refuses_invalid_keys_on_read(string $key): void
    {
        $this->expectException(InvalidSettingKeyException::class);

        Setting::for()->getValue($key);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'leading dot' => ['.x'],
            'trailing dot' => ['x.'],
            'double dot' => ['x..y'],
            'too long' => [str_repeat('k', 192)],
            'invalid UTF-8' => ["\xB1"],
        ];
    }

    public function test_dotted_keys_stay_exact(): void
    {
        Setting::for()->setValue('mail.from', 'a');
        Setting::for()->setValue('mail.from.address', 'b');

        $this->assertSame(['mail.from' => 'a', 'mail.from.address' => 'b'], Setting::for()->allValues());
    }
}
