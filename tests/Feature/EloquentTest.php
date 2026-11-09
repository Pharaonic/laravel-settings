<?php

use Illuminate\Database\Eloquent\Collection;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Exceptions\UnscopedSettingQueryException;
use Pharaonic\Laravel\Settings\Models\Setting;

beforeEach(function () {
    Setting::for()->setValue('site.name', 'Pharaonic');
    Setting::for()->setValue('site.count', 3);
    Setting::for('checkout')->setValue('minimum', 100);
    Setting::for('checkout')->setValue('label', 'Checkout');
});

dataset('eloquent_constraints', function () {
    $cases = [];

    foreach (['where', 'limit', 'select', 'join', 'distinct'] as $constraint) {
        foreach (['getValue', 'allValues', 'setValue', 'forgetValue'] as $method) {
            $cases["{$constraint} {$method}"] = [$constraint, $method];
        }
    }

    return $cases;
});

test('get returns setting models', function () {
    $rows = Setting::for()->get();

    $this->assertInstanceOf(Collection::class, $rows);
    $this->assertCount(2, $rows);
    $this->assertInstanceOf(Setting::class, $rows->first());
    $this->assertSame(['site.count', 'site.name'], $rows->pluck('key')->sort()->values()->all());
});

test('get honors where and order by', function () {
    $rows = Setting::for('checkout')->where('type', 'integer')->get();

    $this->assertCount(1, $rows);
    $this->assertSame('minimum', $rows->first()->key);
    $this->assertSame(100, $rows->first()->value);
    $this->assertSame(['label', 'minimum'], Setting::for('checkout')->orderBy('key')->pluck('key')->all());
});

test('every query uses setting builder', function () {
    $this->assertInstanceOf(SettingBuilder::class, Setting::query());
    $this->assertInstanceOf(SettingBuilder::class, Setting::for());
    $this->assertSame(2, Setting::query()->where('identifier', Setting::GLOBAL)->count());
});

test('the model returns the typed value and the attributes hold the json', function () {
    $row = Setting::for()->where('key', 'site.count')->firstOrFail();

    $this->assertSame(3, $row->value);
    $this->assertSame('3', $row->getAttributes()['value']);
    $this->assertSame('integer', $row->type);
    $this->assertFalse($row->nullable);
});

test('value methods need for', function () {
    $this->expectException(UnscopedSettingQueryException::class);

    Setting::query()->getValue('site.name');
});

test('value writes need for', function () {
    $this->expectException(UnscopedSettingQueryException::class);

    Setting::query()->setValue('site.name', 'x');
});

test('value methods refuse extra constraints', function (string $constraint, string $method) {
    $builder = Setting::for();

    switch ($constraint) {
        case 'where':
            $builder->where('key', 'site.count');

            break;
        case 'limit':
            $builder->limit(1);

            break;
        case 'select':
            $builder->select('key');

            break;
        case 'join':
            $builder->join('companies', 'companies.id', '=', 'settings.id');

            break;
        default:
            $builder->distinct();
    }

    $this->expectException(UnscopedSettingQueryException::class);

    switch ($method) {
        case 'getValue':
            $builder->getValue('site.name');

            break;
        case 'allValues':
            $builder->allValues();

            break;
        case 'setValue':
            $builder->setValue('site.name', 'x');

            break;
        default:
            $builder->forgetValue('site.name');
    }
})->with('eloquent_constraints');

test('value methods refuse another connection', function () {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $this->expectException(UnscopedSettingQueryException::class);

    Setting::on('other')->for()->getValue('site.name');
});

test('value methods allow order by', function () {
    $this->assertSame('Pharaonic', Setting::for()->orderBy('key')->getValue('site.name'));
});

test('it refuses a second for', function () {
    $this->expectException(LogicException::class);

    Setting::for()->for('checkout');
});

test('native saves are validated like set value', function () {
    $row = Setting::for()->where('key', 'site.count')->firstOrFail();
    $row->value = 4;
    $row->save();

    $this->assertSame(4, Setting::for()->getValue('site.count'));

    $row->value = 'four';

    try {
        $row->save();
        $this->fail('The save was not refused.');
    } catch (SettingTypeMismatchException $exception) {
        $this->assertSame(4, Setting::for()->getValue('site.count'));
    }
});

test('settings can be created through the model', function () {
    $setting = Setting::create(['identifier' => 'models', 'key' => 'flag', 'value' => true]);

    $this->assertSame('boolean', $setting->type);
    $this->assertFalse($setting->nullable);
    $this->assertTrue(Setting::for('models')->getValue('flag'));
    $this->assertSame(Setting::GLOBAL, Setting::create(['key' => 'global.flag', 'value' => false])->identifier);
});

test('a stored setting cannot move to another scope', function () {
    $row = Setting::for()->where('key', 'site.count')->firstOrFail();
    $row->identifier = 'other';

    $this->expectException(LogicException::class);

    $row->save();
});
