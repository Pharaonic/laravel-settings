<?php

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Tests\Fixtures\Company;
use Pharaonic\Laravel\Settings\Tests\Fixtures\SoftCompany;

test('settings are scoped to the model', function () {
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
});

test('get setting returns the definition default', function () {
    config(['pharaonic.settings.definitions' => ['theme' => ['type' => 'string', 'default' => 'system']]]);
    $company = Company::create();

    $this->assertSame('system', $company->getSetting('theme'));
    $this->assertSame('light', $company->getSetting('theme', 'light'));
});

test('settings are written right away without saving the model', function () {
    $company = Company::create(['name' => 'Before']);
    $company->name = 'Unsaved';

    $company->setSetting('theme', 'dark');

    $this->assertSame(1, Setting::query()->count());
    $this->assertSame('Before', $company->fresh()?->name);
});

test('loading and saving the model runs no settings query', function () {
    $company = Company::create();
    $company->setSetting('theme', 'dark');

    $count = $this->countQueries(function () use ($company) {
        $loaded = Company::query()->findOrFail($company->getKey());
        $loaded->name = 'Changed';
        $loaded->save();
    });

    $this->assertSame(0, $count);
});

test('settings is not a relation attribute', function () {
    $company = Company::create();

    $this->expectException(LogicException::class);

    $company->settings;
});

test('settings cannot be eager loaded', function () {
    Company::create();

    $this->expectException(LogicException::class);

    Company::query()->with('settings')->get();
});

test('an unsaved model is refused', function () {
    $this->expectException(InvalidIdentifierException::class);

    (new Company)->setSetting('theme', 'dark');
});

test('a soft delete and a restore keep the settings', function () {
    config(['pharaonic.settings.models.purge_on_force_delete' => true]);
    $company = SoftCompany::create();
    $company->setSetting('theme', 'dark');

    $company->delete();
    $this->assertSame(1, Setting::query()->count());

    $company->restore();
    $this->assertSame('dark', $company->getSetting('theme'));
});

test('a permanent delete keeps the settings by default', function () {
    $company = Company::create();
    $company->setSetting('theme', 'dark');
    $soft = SoftCompany::create();
    $soft->setSetting('theme', 'dark');

    $company->delete();
    $soft->forceDelete();

    $this->assertSame(2, Setting::query()->count());
});

test('a permanent delete purges the settings when enabled', function () {
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
});

test('a purging delete that rolls back keeps the settings', function () {
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
});
