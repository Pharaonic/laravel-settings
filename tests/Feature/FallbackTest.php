<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Branch;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class FallbackTest extends TestCase
{
    private Company $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create();
        $this->branch = Branch::create();
    }

    private function chain(): \Pharaonic\Laravel\Settings\Builders\SettingBuilder
    {
        return settings($this->branch)->fallbackTo($this->company)->fallbackTo(null);
    }

    public function test_it_resolves_branch_then_company_then_global_then_the_default(): void
    {
        settings()->setValue('theme', 'global');
        $this->assertSame('global', $this->chain()->getValue('theme', 'light'));

        settings($this->company)->setValue('theme', 'company');
        $this->assertSame('company', $this->chain()->getValue('theme', 'light'));

        settings($this->branch)->setValue('theme', 'branch');
        $this->assertSame('branch', $this->chain()->getValue('theme', 'light'));
        $this->assertSame('light', $this->chain()->getValue('missing', 'light'));
    }

    public function test_it_never_falls_back_without_fallback_to(): void
    {
        settings()->setValue('theme', 'global');

        $this->assertSame('light', settings($this->branch)->getValue('theme', 'light'));
        $this->assertFalse(settings($this->branch)->hasValue('theme'));
    }

    public function test_it_stops_at_a_stored_null(): void
    {
        settings()->setValue('theme', 'global');
        settings($this->company)->setValue('theme', null);

        $this->assertNull($this->chain()->getValue('theme', 'light'));
        $this->assertTrue(settings($this->branch)->fallbackTo($this->company)->hasValue('theme'));
    }

    public function test_the_own_methods_read_only_the_primary_scope(): void
    {
        settings($this->company)->setValue('theme', 'company');
        $chain = settings($this->branch)->fallbackTo($this->company);

        $this->assertSame('none', $chain->getOwnValue('theme', 'none'));
        $this->assertFalse($chain->hasOwnValue('theme'));
        $this->assertSame([], $chain->allOwnValues());
        $this->assertTrue($chain->hasValue('theme'));
    }

    public function test_duplicate_scopes_are_skipped_in_first_seen_order(): void
    {
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
    }

    public function test_all_values_lets_the_earliest_scope_win(): void
    {
        settings()->setValue('a', 'global');
        settings()->setValue('b', 'global');
        settings()->setValue('c', 'global');
        settings($this->company)->setValue('b', 'company');
        settings($this->company)->setValue('c', 'company');
        settings($this->branch)->setValue('c', 'branch');
        settings($this->branch)->setValue('d', null);

        $this->assertSame(['a' => 'global', 'b' => 'company', 'c' => 'branch', 'd' => null], $this->chain()->allValues());
    }

    public function test_explain_value_reports_where_a_value_comes_from(): void
    {
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
        ], $this->chain()->explainValue('branding.primary_color'));

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
        ], $this->chain()->explainValue('branding.primary_color'));

        settings($this->branch)->setValue('branding.primary_color', '#111111');
        $explained = $this->chain()->explainValue('branding.primary_color');

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
    }

    public function test_fallback_to_global_is_fallback_to_null(): void
    {
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
    }

    public function test_fallback_to_global_on_the_global_scope_adds_nothing(): void
    {
        $this->assertSame([Setting::GLOBAL], settings()->fallbackToGlobal()->getFallbackChain());
        $this->assertSame([Setting::GLOBAL, 'checkout'], settings()->fallbackTo('checkout')->fallbackToGlobal()->getFallbackChain());
    }

    public function test_fallback_to_global_needs_a_plain_scope(): void
    {
        $this->expectException(\Pharaonic\Laravel\Settings\Exceptions\UnscopedSettingQueryException::class);

        Setting::query()->fallbackToGlobal();
    }

    public function test_writes_only_reach_the_primary_scope(): void
    {
        settings($this->branch)->fallbackTo($this->company)->setValue('theme', 'dark');

        $this->assertFalse(settings($this->company)->hasValue('theme'));
        $this->assertSame('dark', settings($this->branch)->getValue('theme'));
    }

    public function test_the_chain_stays_on_its_own_builder(): void
    {
        settings($this->company)->setValue('theme', 'company');

        settings($this->branch)->fallbackTo($this->company);

        $this->assertSame('none', settings($this->branch)->getValue('theme', 'none'));
    }
}
