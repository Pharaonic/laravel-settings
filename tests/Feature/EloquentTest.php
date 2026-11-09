<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Exceptions\UnscopedSettingQueryException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class EloquentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Setting::for()->setValue('site.name', 'Pharaonic');
        Setting::for()->setValue('site.count', 3);
        Setting::for('checkout')->setValue('minimum', 100);
        Setting::for('checkout')->setValue('label', 'Checkout');
    }

    public function test_get_returns_setting_models(): void
    {
        $rows = Setting::for()->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(Setting::class, $rows->first());
        $this->assertSame(['site.count', 'site.name'], $rows->pluck('key')->sort()->values()->all());
    }

    public function test_get_honors_where_and_order_by(): void
    {
        $rows = Setting::for('checkout')->where('type', 'integer')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('minimum', $rows->first()->key);
        $this->assertSame(100, $rows->first()->value);
        $this->assertSame(['label', 'minimum'], Setting::for('checkout')->orderBy('key')->pluck('key')->all());
    }

    public function test_every_query_uses_setting_builder(): void
    {
        $this->assertInstanceOf(SettingBuilder::class, Setting::query());
        $this->assertInstanceOf(SettingBuilder::class, Setting::for());
        $this->assertSame(2, Setting::query()->where('identifier', Setting::GLOBAL)->count());
    }

    public function test_the_model_returns_the_typed_value_and_the_attributes_hold_the_json(): void
    {
        $row = Setting::for()->where('key', 'site.count')->firstOrFail();

        $this->assertSame(3, $row->value);
        $this->assertSame('3', $row->getAttributes()['value']);
        $this->assertSame('integer', $row->type);
        $this->assertFalse($row->nullable);
    }

    public function test_value_methods_need_for(): void
    {
        $this->expectException(UnscopedSettingQueryException::class);

        Setting::query()->getValue('site.name');
    }

    public function test_value_writes_need_for(): void
    {
        $this->expectException(UnscopedSettingQueryException::class);

        Setting::query()->setValue('site.name', 'x');
    }

    /**
     * @dataProvider constraints
     */
    public function test_value_methods_refuse_extra_constraints(string $constraint, string $method): void
    {
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
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function constraints(): array
    {
        $cases = [];

        foreach (['where', 'limit', 'select', 'join', 'distinct'] as $constraint) {
            foreach (['getValue', 'allValues', 'setValue', 'forgetValue'] as $method) {
                $cases["{$constraint} {$method}"] = [$constraint, $method];
            }
        }

        return $cases;
    }

    public function test_value_methods_refuse_another_connection(): void
    {
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        $this->expectException(UnscopedSettingQueryException::class);

        Setting::on('other')->for()->getValue('site.name');
    }

    public function test_value_methods_allow_order_by(): void
    {
        $this->assertSame('Pharaonic', Setting::for()->orderBy('key')->getValue('site.name'));
    }

    public function test_it_refuses_a_second_for(): void
    {
        $this->expectException(LogicException::class);

        Setting::for()->for('checkout');
    }

    public function test_native_saves_are_validated_like_set_value(): void
    {
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
    }

    public function test_settings_can_be_created_through_the_model(): void
    {
        $setting = Setting::create(['identifier' => 'models', 'key' => 'flag', 'value' => true]);

        $this->assertSame('boolean', $setting->type);
        $this->assertFalse($setting->nullable);
        $this->assertTrue(Setting::for('models')->getValue('flag'));
        $this->assertSame(Setting::GLOBAL, Setting::create(['key' => 'global.flag', 'value' => false])->identifier);
    }

    public function test_a_stored_setting_cannot_move_to_another_scope(): void
    {
        $row = Setting::for()->where('key', 'site.count')->firstOrFail();
        $row->identifier = 'other';

        $this->expectException(LogicException::class);

        $row->save();
    }
}
