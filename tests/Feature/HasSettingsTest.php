<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LogicException;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\Fixtures\SoftCompany;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use RuntimeException;

final class HasSettingsTest extends TestCase
{
    public function test_settings_are_scoped_to_the_model(): void
    {
        $company = Company::create();
        $other = Company::create();

        $company->setSetting('theme', 'dark');

        $this->assertInstanceOf(SettingBuilder::class, $company->settings());
        $this->assertCount(1, $company->settings()->get());
        $this->assertInstanceOf(Setting::class, $company->settings()->get()->first());
        $this->assertSame('dark', $company->getSetting('theme', 'light'));
        $this->assertSame('dark', $company->settings()->getValue('theme', 'light'));
        $this->assertSame('light', $other->getSetting('theme', 'light'));
        $this->assertTrue($company->hasSetting('theme'));
        $this->assertSame(['theme' => 'dark'], $company->allSettings());
        $this->assertTrue($company->forgetSetting('theme'));
        $this->assertFalse($company->hasSetting('theme'));
    }

    public function test_get_setting_returns_the_definition_default(): void
    {
        config(['pharaonic.settings.definitions' => ['theme' => ['type' => 'string', 'default' => 'system']]]);
        $company = Company::create();

        $this->assertSame('system', $company->getSetting('theme'));
        $this->assertSame('light', $company->getSetting('theme', 'light'));
    }

    public function test_settings_are_written_right_away_without_saving_the_model(): void
    {
        $company = Company::create(['name' => 'Before']);
        $company->name = 'Unsaved';

        $company->setSetting('theme', 'dark');

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('Before', $company->fresh()?->name);
    }

    public function test_loading_and_saving_the_model_runs_no_settings_query(): void
    {
        $company = Company::create();
        $company->setSetting('theme', 'dark');

        $count = $this->countQueries(function () use ($company) {
            $loaded = Company::query()->findOrFail($company->getKey());
            $loaded->name = 'Changed';
            $loaded->save();
        });

        $this->assertSame(0, $count);
    }

    public function test_settings_is_not_a_relation_attribute(): void
    {
        $company = Company::create();

        $this->expectException(LogicException::class);

        $company->settings;
    }

    public function test_settings_cannot_be_eager_loaded(): void
    {
        Company::create();

        $this->expectException(LogicException::class);

        Company::query()->with('settings')->get();
    }

    public function test_an_unsaved_model_is_refused(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        (new Company)->setSetting('theme', 'dark');
    }

    public function test_a_soft_delete_and_a_restore_keep_the_settings(): void
    {
        config(['pharaonic.settings.models.purge_on_force_delete' => true]);
        $company = SoftCompany::create();
        $company->setSetting('theme', 'dark');

        $company->delete();
        $this->assertSame(1, Setting::query()->count());

        $company->restore();
        $this->assertSame('dark', $company->getSetting('theme'));
    }

    public function test_a_permanent_delete_keeps_the_settings_by_default(): void
    {
        $company = Company::create();
        $company->setSetting('theme', 'dark');
        $soft = SoftCompany::create();
        $soft->setSetting('theme', 'dark');

        $company->delete();
        $soft->forceDelete();

        $this->assertSame(2, Setting::query()->count());
    }

    public function test_a_permanent_delete_purges_the_settings_when_enabled(): void
    {
        config(['pharaonic.settings.models.purge_on_force_delete' => true]);
        $company = Company::create();
        $company->setSetting('theme', 'dark');
        $soft = SoftCompany::create();
        $soft->setSetting('theme', 'dark');
        $other = Company::create();
        $other->setSetting('theme', 'light');
        $identifier = (string) Setting::for($company)->getIdentifier();

        settings($identifier)->getValue('theme');
        $company->delete();
        $soft->forceDelete();

        $this->assertSame(1, Setting::query()->count());
        $this->assertFalse(settings($identifier)->hasValue('theme'));
        $this->assertSame('light', $other->getSetting('theme'));
    }

    public function test_a_purging_delete_that_rolls_back_keeps_the_settings(): void
    {
        config(['pharaonic.settings.models.purge_on_force_delete' => true]);
        $company = Company::create();
        $company->setSetting('theme', 'dark');

        try {
            DB::transaction(function () use ($company) {
                $company->delete();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            //
        }

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame(1, Company::query()->count());
    }
}
