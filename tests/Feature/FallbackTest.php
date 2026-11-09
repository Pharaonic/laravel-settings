<?php

use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Branch;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;

beforeEach(function () {
    $this->company = Company::create();
    $this->branch = Branch::create();
});

function fallback_chain(\Pharaonic\Laravel\Settings\Tests\TestCase $test): \Pharaonic\Laravel\Settings\Builders\SettingBuilder
{
    return settings($test->branch)->fallbackTo($test->company)->fallbackTo(null);
}

test('it resolves branch then company then global then the default', function () {
    settings()->setValue('theme', 'global');
    $this->assertSame('global', fallback_chain($this)->getValue('theme', 'light'));

    settings($this->company)->setValue('theme', 'company');
    $this->assertSame('company', fallback_chain($this)->getValue('theme', 'light'));

    settings($this->branch)->setValue('theme', 'branch');
    $this->assertSame('branch', fallback_chain($this)->getValue('theme', 'light'));
    $this->assertSame('light', fallback_chain($this)->getValue('missing', 'light'));
});

test('it never falls back without fallback to', function () {
    settings()->setValue('theme', 'global');

    $this->assertSame('light', settings($this->branch)->getValue('theme', 'light'));
    $this->assertFalse(settings($this->branch)->hasValue('theme'));
});

test('it stops at a stored null', function () {
    settings()->setValue('theme', 'global');
    settings($this->company)->setValue('theme', null);

    $this->assertNull(fallback_chain($this)->getValue('theme', 'light'));
    $this->assertTrue(settings($this->branch)->fallbackTo($this->company)->hasValue('theme'));
});

test('the own methods read only the primary scope', function () {
    settings($this->company)->setValue('theme', 'company');
    $chain = settings($this->branch)->fallbackTo($this->company);

    $this->assertSame('none', $chain->getOwnValue('theme', 'none'));
    $this->assertFalse($chain->hasOwnValue('theme'));
    $this->assertSame([], $chain->allOwnValues());
    $this->assertTrue($chain->hasValue('theme'));
});

test('duplicate scopes are skipped in first seen order', function () {
    $chain = settings($this->branch)
        ->fallbackTo($this->company)
        ->fallbackTo($this->branch)
        ->fallbackTo(null)
        ->fallbackTo(Setting::GLOBAL)
        ->fallbackTo($this->company);

    $this->assertSame([
        Branch::class.':'.$this->branch->getKey(),
        Company::class.':'.$this->company->getKey(),
        Setting::GLOBAL,
    ], $chain->getFallbackChain());
});

test('all values lets the earliest scope win', function () {
    settings()->setValue('a', 'global');
    settings()->setValue('b', 'global');
    settings()->setValue('c', 'global');
    settings($this->company)->setValue('b', 'company');
    settings($this->company)->setValue('c', 'company');
    settings($this->branch)->setValue('c', 'branch');
    settings($this->branch)->setValue('d', null);

    $this->assertSame(['a' => 'global', 'b' => 'company', 'c' => 'branch', 'd' => null], fallback_chain($this)->allValues());
});

test('explain value reports where a value comes from', function () {
    config(['pharaonic.settings.definitions' => ['branding.primary_color' => ['type' => 'string', 'default' => '#000000']]]);

    $this->assertSame([
        'key' => 'branding.primary_color',
        'found' => false,
        'value' => '#000000',
        'source' => null,
        'inherited' => false,
        'is_default' => true,
        'type' => 'string',
        'nullable' => false,
    ], fallback_chain($this)->explainValue('branding.primary_color'));

    settings($this->company)->setValue('branding.primary_color', '#0EA5E9');

    $this->assertSame([
        'key' => 'branding.primary_color',
        'found' => true,
        'value' => '#0EA5E9',
        'source' => Company::class.':'.$this->company->getKey(),
        'inherited' => true,
        'is_default' => false,
        'type' => 'string',
        'nullable' => false,
    ], fallback_chain($this)->explainValue('branding.primary_color'));

    settings($this->branch)->setValue('branding.primary_color', '#111111');
    $explained = fallback_chain($this)->explainValue('branding.primary_color');

    $this->assertSame(Branch::class.':'.$this->branch->getKey(), $explained['source']);
    $this->assertFalse($explained['inherited']);

    $this->assertSame([
        'key' => 'nothing',
        'found' => false,
        'value' => null,
        'source' => null,
        'inherited' => false,
        'is_default' => false,
        'type' => null,
        'nullable' => null,
    ], settings()->explainValue('nothing'));
});

test('fallback to global is fallback to null', function () {
    settings()->setValue('theme', 'global');

    $chain = settings($this->branch)->fallbackTo($this->company)->fallbackToGlobal();

    $this->assertSame([
        Branch::class.':'.$this->branch->getKey(),
        Company::class.':'.$this->company->getKey(),
        Setting::GLOBAL,
    ], $chain->getFallbackChain());
    $this->assertSame('global', $chain->getValue('theme', 'light'));
    $this->assertSame(
        settings($this->branch)->fallbackTo(null)->getFallbackChain(),
        settings($this->branch)->fallbackToGlobal()->getFallbackChain()
    );
});

test('fallback to global on the global scope adds nothing', function () {
    $this->assertSame([Setting::GLOBAL], settings()->fallbackToGlobal()->getFallbackChain());
    $this->assertSame([Setting::GLOBAL, 'checkout'], settings()->fallbackTo('checkout')->fallbackToGlobal()->getFallbackChain());
});

test('fallback to global needs a plain scope', function () {
    $this->expectException(\Pharaonic\Laravel\Settings\Exceptions\UnscopedSettingQueryException::class);

    Setting::query()->fallbackToGlobal();
});

test('writes only reach the primary scope', function () {
    settings($this->branch)->fallbackTo($this->company)->setValue('theme', 'dark');

    $this->assertFalse(settings($this->company)->hasValue('theme'));
    $this->assertSame('dark', settings($this->branch)->getValue('theme'));
});

test('the chain stays on its own builder', function () {
    settings($this->company)->setValue('theme', 'company');

    settings($this->branch)->fallbackTo($this->company);

    $this->assertSame('none', settings($this->branch)->getValue('theme', 'none'));
});
