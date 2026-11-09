<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Exceptions\InvalidSettingKeyException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\Fixtures\UuidModel;

dataset('identifier_invalidIdentifiers', function () {
    return [
        'empty' => [''],
        'too long' => [str_repeat('a', 192)],
        'invalid UTF-8' => ["\xB1\x31"],
    ];
});

dataset('identifier_invalidKeys', function () {
    return [
        'empty' => [''],
        'leading dot' => ['.x'],
        'trailing dot' => ['x.'],
        'double dot' => ['x..y'],
        'too long' => [str_repeat('k', 192)],
        'invalid UTF-8' => ["\xB1"],
    ];
});

test('omitted null and global are the one global scope', function () {
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
});

test('named scopes are apart from each other and from the global scope', function () {
    Setting::for()->setValue('minimum', 1);
    Setting::for('checkout')->setValue('minimum', 100);
    Setting::for('Checkout')->setValue('minimum', 200);

    $this->assertSame(1, Setting::for()->getValue('minimum'));
    $this->assertSame(100, Setting::for('checkout')->getValue('minimum'));
    $this->assertSame(200, Setting::for('Checkout')->getValue('minimum'));
    $this->assertFalse(Setting::for('global')->hasValue('minimum'));
});

test('a model is its morph class and key the same as the string', function () {
    $company = Company::create();
    Setting::for($company)->setValue('timezone', 'Africa/Cairo');
    $identifier = Company::class.':'.$company->getKey();

    $this->assertSame($identifier, Setting::for($company)->getIdentifier());
    $this->assertSame('Africa/Cairo', Setting::for($identifier)->getValue('timezone'));
    $this->assertSame('Africa/Cairo', settings($company)->getValue('timezone'));
    $this->assertSame('Africa/Cairo', $company->settings()->getValue('timezone'));
});

test('it honors the morph map', function () {
    Relation::morphMap(['company' => Company::class]);
    $company = Company::create();

    Setting::for($company)->setValue('theme', 'dark');

    $this->assertSame('company:'.$company->getKey(), Setting::for($company)->getIdentifier());
    $this->assertSame('dark', Setting::for('company:'.$company->getKey())->getValue('theme'));
});

test('it accepts string and zero keys', function () {
    $uuid = UuidModel::create(['id' => '9b2f0c1e-0000-4000-8000-000000000000']);
    $zero = new Company;
    $zero->id = 0;
    $zero->exists = true;
    $stringZero = new UuidModel(['id' => '0']);
    $stringZero->exists = true;

    $this->assertSame(UuidModel::class.':9b2f0c1e-0000-4000-8000-000000000000', Setting::for($uuid)->getIdentifier());
    $this->assertSame(Company::class.':0', Setting::for($zero)->getIdentifier());
    $this->assertSame(UuidModel::class.':0', Setting::for($stringZero)->getIdentifier());
});

test('it refuses an unsaved model', function () {
    $this->expectException(InvalidIdentifierException::class);

    Setting::for(new Company);
});

test('it refuses a model without a key', function () {
    $keyless = new Company;
    $keyless->exists = true;

    $this->expectException(InvalidIdentifierException::class);

    Setting::for($keyless);
});

test('it refuses a model with an empty key', function () {
    $empty = new UuidModel(['id' => '']);
    $empty->exists = true;

    $this->expectException(InvalidIdentifierException::class);

    Setting::for($empty);
});

test('it refuses invalid identifiers', function (string $identifier) {
    $this->expectException(InvalidIdentifierException::class);

    Setting::for($identifier);
})->with('identifier_invalidIdentifiers');

test('it accepts an identifier of the maximum length', function () {
    $identifier = str_repeat('é', 191);

    Setting::for($identifier)->setValue('x', 1);

    $this->assertSame(1, Setting::for($identifier)->getValue('x'));
});

test('it refuses invalid keys on write', function (string $key) {
    $this->expectException(InvalidSettingKeyException::class);

    Setting::for()->setValue($key, 1);
})->with('identifier_invalidKeys');

test('it refuses invalid keys on read', function (string $key) {
    $this->expectException(InvalidSettingKeyException::class);

    Setting::for()->getValue($key);
})->with('identifier_invalidKeys');

test('dotted keys stay exact', function () {
    Setting::for()->setValue('mail.from', 'a');
    Setting::for()->setValue('mail.from.address', 'b');

    $this->assertSame(['mail.from' => 'a', 'mail.from.address' => 'b'], Setting::for()->allValues());
});
