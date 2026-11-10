<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Support\ScopeCache;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Branch;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class CacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_scope_is_read_from_the_cache_after_the_first_read(): void
    {
        settings()->setValue('a', 1);
        settings()->setValue('b', 2);
        settings()->getValue('a');

        $count = $this->countQueries(function () {
            settings()->getValue('a');
            settings()->getValue('b');
            settings()->allValues();
            settings()->hasValue('missing');
        });

        $this->assertSame(0, $count);
    }

    public function test_every_read_queries_with_the_cache_disabled(): void
    {
        config(['pharaonic.settings.cache.enabled' => false]);
        settings()->setValue('a', 1);

        $count = $this->countQueries(function () {
            settings()->getValue('a');
            settings()->getValue('a');
        });

        $this->assertSame(2, $count);
    }

    public function test_global_named_and_model_scopes_have_separate_entries(): void
    {
        $company = Company::create();
        settings()->setValue('theme', 'global');
        settings('tenant')->setValue('theme', 'tenant');
        settings($company)->setValue('theme', 'company');

        $this->assertSame('global', settings()->getValue('theme'));
        $this->assertSame('tenant', settings('tenant')->getValue('theme'));
        $this->assertSame('company', settings($company)->getValue('theme'));
        $this->assertNull(settings('global')->getValue('theme'));

        settings('tenant')->setValue('theme', 'tenant 2');

        $this->assertSame('global', settings()->getValue('theme'));
        $this->assertSame('tenant 2', settings('tenant')->getValue('theme'));
    }

    public function test_a_write_forgets_only_its_own_scope(): void
    {
        settings()->setValue('a', 1);
        settings('tenant')->setValue('a', 1);
        settings()->getValue('a');
        settings('tenant')->getValue('a');

        settings('tenant')->setValue('a', 2);

        $this->assertSame(0, $this->countQueries(function () {
            settings()->getValue('a');
        }));
        $this->assertSame(2, settings('tenant')->getValue('a'));
    }

    public function test_a_fallback_scope_change_is_seen_right_away(): void
    {
        settings()->setValue('theme', 'one');

        $this->assertSame('one', settings('tenant')->fallbackTo(null)->getValue('theme'));

        settings()->setValue('theme', 'two');

        $this->assertSame('two', settings('tenant')->fallbackTo(null)->getValue('theme'));
    }

    public function test_a_native_save_and_delete_forget_the_cache(): void
    {
        $setting = settings()->setValue('theme', 'one');
        settings()->getValue('theme');

        $setting->value = 'two';
        $setting->save();

        $this->assertSame('two', settings()->getValue('theme'));

        $setting->delete();

        $this->assertFalse(settings()->hasValue('theme'));
    }

    public function test_a_transaction_reads_its_own_writes(): void
    {
        settings()->setValue('theme', 'light');
        settings()->getValue('theme');

        DB::transaction(function () {
            settings()->setValue('theme', 'dark');

            $this->assertSame('dark', settings()->getValue('theme'));
        });

        $this->assertSame('dark', settings()->getValue('theme'));
    }

    public function test_a_rollback_keeps_the_committed_value_cached(): void
    {
        settings()->setValue('theme', 'light');
        settings()->getValue('theme');

        try {
            DB::transaction(function () {
                settings()->setValue('theme', 'dark');

                $this->assertSame('dark', settings()->getValue('theme'));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            //
        }

        $this->assertSame('light', settings()->getValue('theme'));
    }

    public function test_the_cache_is_forgotten_only_once_the_outermost_transaction_commits(): void
    {
        settings()->setValue('theme', 'light');
        settings()->getValue('theme');
        $version = $this->versionReader();
        $before = $version();

        DB::beginTransaction();
        DB::transaction(function () {
            settings()->setValue('theme', 'dark');
        });

        // The inner commit is only a savepoint: other processes still read the
        // committed value, so the cached rows stay.
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame($before, $version());

        DB::commit();

        $this->assertNotSame($before, $version());
        $this->assertSame('dark', settings()->getValue('theme'));
    }

    public function test_another_server_sees_the_invalidation_through_a_shared_store(): void
    {
        config(['pharaonic.settings.cache.store' => 'shared']);
        config(['cache.stores.shared' => ['driver' => 'file', 'path' => sys_get_temp_dir().'/laravel-settings-cache-'.getmypid()]]);
        Cache::store('shared')->flush();

        settings()->setValue('theme', 'one');
        $this->assertSame('one', settings()->getValue('theme'));

        // Another server: its own store instance over the same files.
        Cache::forgetDriver('shared');
        settings()->setValue('theme', 'two');
        Cache::forgetDriver('shared');

        $this->assertSame('two', settings()->getValue('theme'));

        Cache::store('shared')->flush();
    }

    public function test_the_invalidation_runs_after_the_commit_in_a_cloned_container_as_under_octane(): void
    {
        settings()->setValue('theme', 'light');
        settings()->getValue('theme');

        // Octane runs each request in a clone of the booted application, while
        // the provider's listeners were registered on the original.
        $base = Container::getInstance();
        Container::setInstance(clone $this->app);

        try {
            DB::transaction(function () {
                settings()->setValue('theme', 'dark');
            });
        } finally {
            Container::setInstance($base);
        }

        $this->assertSame('dark', settings()->getValue('theme'));
    }

    public function test_no_pending_invalidation_is_left_behind(): void
    {
        DB::beginTransaction();
        settings()->setValue('theme', 'dark');
        DB::rollBack();

        $pending = new ReflectionProperty(ScopeCache::class, 'pending');
        $pending->setAccessible(true);
        $map = $pending->getValue();

        $this->assertTrue($map === null || count($map) === 0);
    }

    public function test_the_configured_store_is_used(): void
    {
        config(['pharaonic.settings.cache.store' => 'secondary']);

        settings()->setValue('theme', 'one');
        settings()->getValue('theme');
        Cache::store('array')->flush();

        $this->assertSame(0, $this->countQueries(fn () => settings()->getValue('theme')));

        Cache::store('secondary')->flush();

        $this->assertSame(1, $this->countQueries(fn () => settings()->getValue('theme')));
    }

    public function test_entries_expire_after_the_configured_ttl(): void
    {
        config(['pharaonic.settings.cache.ttl' => 60]);
        settings()->setValue('theme', 'one');
        settings()->getValue('theme');

        Setting::query()->toBase()->update(['value' => '"out of band"']);

        $this->assertSame('one', settings()->getValue('theme'));

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        $this->assertSame('out of band', settings()->getValue('theme'));
    }

    public function test_direct_database_writes_are_not_seen_until_the_next_package_write_to_the_scope(): void
    {
        settings()->setValue('theme', 'one');
        settings()->getValue('theme');

        Setting::query()->toBase()->update(['value' => '"direct"']);

        $this->assertSame('one', settings()->getValue('theme'));

        settings()->setValue('other', 1);

        $this->assertSame('direct', settings()->getValue('theme'));
    }

    public function test_tenants_never_share_entries(): void
    {
        settings('tenant-a')->setValue('theme', 'a');
        settings('tenant-b')->setValue('theme', 'b');

        $this->assertSame('a', settings('tenant-a')->getValue('theme'));
        $this->assertSame('b', settings('tenant-b')->getValue('theme'));

        Cache::flush();

        $this->assertSame('a', settings('tenant-a')->getValue('theme'));
    }

    /**
     * @return \Closure(): mixed
     */
    private function versionReader(): \Closure
    {
        $baseKey = new ReflectionMethod(ScopeCache::class, 'baseKey');
        $baseKey->setAccessible(true);
        $key = $baseKey->invoke(new ScopeCache, DB::connection(), 'settings', Setting::GLOBAL).':version';

        return function () use ($key) {
            return Cache::get($key);
        };
    }

    public function test_a_company_change_reaches_every_cached_branch_chain(): void
    {
        $company = Company::create();
        $branches = [Branch::create(), Branch::create()];
        $chain = function (Branch $branch) use ($company) {
            return settings($branch)->fallbackTo($company)->fallbackToGlobal();
        };
        $read = function () use ($branches, $chain): array {
            return array_map(function (Branch $branch) use ($chain) {
                return $chain($branch)->getValue('limit', 'default');
            }, $branches);
        };

        settings()->setValue('limit', 100);
        settings($company)->setValue('limit', 50, 'integer', true);
        settings($branches[1])->setValue('other', 'kept');

        $this->assertSame([50, 50], $read());
        $this->assertSame(0, $this->countQueries($read));

        settings($company)->setValue('limit', 0);

        // Only the company scope is read again; the branch scopes stay cached.
        $this->assertSame(1, $this->countQueries(function () use ($read) {
            $this->assertSame([0, 0], $read());
        }));

        settings($company)->setValue('limit', null);
        $this->assertSame([null, null], $read());

        settings($company)->forgetValue('limit');
        $this->assertSame([100, 100], $read());

        settings($company)->setValue('limit', 25);
        $this->assertSame([25, 25], $read());

        $row = Setting::for($company)->where('key', 'limit')->firstOrFail();
        $row->value = 30;
        $row->save();
        $this->assertSame([30, 30], $read());

        $company->setSetting('limit', 40);
        $this->assertSame([40, 40], $read());

        settings($branches[0])->setValue('limit', 5);
        settings($company)->setValue('limit', 60);
        $this->assertSame([5, 60], $read());
        $this->assertSame('kept', $chain($branches[1])->getValue('other'));
    }

    public function test_a_company_change_inside_a_transaction_reaches_cached_branches_after_the_commit(): void
    {
        $company = Company::create();
        $branch = Branch::create();
        $chain = function () use ($company, $branch) {
            return settings($branch)->fallbackTo($company)->fallbackToGlobal();
        };

        settings($company)->setValue('enabled', true);
        $this->assertTrue($chain()->getValue('enabled'));

        DB::transaction(function () use ($company, $chain) {
            settings($company)->setValue('enabled', false);

            $this->assertFalse($chain()->getValue('enabled'));
        });

        $this->assertFalse($chain()->getValue('enabled'));
    }
}
