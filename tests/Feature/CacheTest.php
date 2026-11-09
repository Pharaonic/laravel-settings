<?php

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Support\ScopeCache;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return \Closure(): mixed
 */
function cache_version_reader(\Pharaonic\Laravel\Settings\Tests\TestCase $test): \Closure
{
    $baseKey = new ReflectionMethod(ScopeCache::class, 'baseKey');
    $baseKey->setAccessible(true);
    $key = $baseKey->invoke(new ScopeCache, DB::connection(), 'settings', Setting::GLOBAL).':version';

    return function () use ($key) {
        return Cache::get($key);
    };
}

test('a scope is read from the cache after the first read', function () {
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
});

test('every read queries with the cache disabled', function () {
    config(['pharaonic.settings.cache.enabled' => false]);
    settings()->setValue('a', 1);

    $count = $this->countQueries(function () {
        settings()->getValue('a');
        settings()->getValue('a');
    });

    $this->assertSame(2, $count);
});

test('global named and model scopes have separate entries', function () {
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
});

test('a write forgets only its own scope', function () {
    settings()->setValue('a', 1);
    settings('tenant')->setValue('a', 1);
    settings()->getValue('a');
    settings('tenant')->getValue('a');

    settings('tenant')->setValue('a', 2);

    $this->assertSame(0, $this->countQueries(function () {
        settings()->getValue('a');
    }));
    $this->assertSame(2, settings('tenant')->getValue('a'));
});

test('a fallback scope change is seen right away', function () {
    settings()->setValue('theme', 'one');

    $this->assertSame('one', settings('tenant')->fallbackTo(null)->getValue('theme'));

    settings()->setValue('theme', 'two');

    $this->assertSame('two', settings('tenant')->fallbackTo(null)->getValue('theme'));
});

test('a native save and delete forget the cache', function () {
    $setting = settings()->setValue('theme', 'one');
    settings()->getValue('theme');

    $setting->value = 'two';
    $setting->save();

    $this->assertSame('two', settings()->getValue('theme'));

    $setting->delete();

    $this->assertFalse(settings()->hasValue('theme'));
});

test('a transaction reads its own writes', function () {
    settings()->setValue('theme', 'light');
    settings()->getValue('theme');

    DB::transaction(function () {
        settings()->setValue('theme', 'dark');

        $this->assertSame('dark', settings()->getValue('theme'));
    });

    $this->assertSame('dark', settings()->getValue('theme'));
});

test('a rollback keeps the committed value cached', function () {
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
});

test('the cache is forgotten only once the outermost transaction commits', function () {
    settings()->setValue('theme', 'light');
    settings()->getValue('theme');
    $version = cache_version_reader($this);
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
});

test('another server sees the invalidation through a shared store', function () {
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
});

test('the invalidation runs after the commit in a cloned container as under octane', function () {
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
});

test('no pending invalidation is left behind', function () {
    DB::beginTransaction();
    settings()->setValue('theme', 'dark');
    DB::rollBack();

    $pending = new ReflectionProperty(ScopeCache::class, 'pending');
    $pending->setAccessible(true);
    $map = $pending->getValue();

    $this->assertTrue($map === null || count($map) === 0);
});

test('the configured store is used', function () {
    config(['pharaonic.settings.cache.store' => 'secondary']);

    settings()->setValue('theme', 'one');
    settings()->getValue('theme');
    Cache::store('array')->flush();

    $this->assertSame(0, $this->countQueries(fn () => settings()->getValue('theme')));

    Cache::store('secondary')->flush();

    $this->assertSame(1, $this->countQueries(fn () => settings()->getValue('theme')));
});

test('entries expire after the configured ttl', function () {
    config(['pharaonic.settings.cache.ttl' => 60]);
    settings()->setValue('theme', 'one');
    settings()->getValue('theme');

    Setting::query()->toBase()->update(['value' => '"out of band"']);

    $this->assertSame('one', settings()->getValue('theme'));

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    $this->assertSame('out of band', settings()->getValue('theme'));
});

test('direct database writes are not seen until the next package write to the scope', function () {
    settings()->setValue('theme', 'one');
    settings()->getValue('theme');

    Setting::query()->toBase()->update(['value' => '"direct"']);

    $this->assertSame('one', settings()->getValue('theme'));

    settings()->setValue('other', 1);

    $this->assertSame('direct', settings()->getValue('theme'));
});

test('tenants never share entries', function () {
    settings('tenant-a')->setValue('theme', 'a');
    settings('tenant-b')->setValue('theme', 'b');

    $this->assertSame('a', settings('tenant-a')->getValue('theme'));
    $this->assertSame('b', settings('tenant-b')->getValue('theme'));

    Cache::flush();

    $this->assertSame('a', settings('tenant-a')->getValue('theme'));
});
