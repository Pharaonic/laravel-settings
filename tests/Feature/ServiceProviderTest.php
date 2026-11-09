<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\SettingsServiceProvider;

dataset('service_provider_configTags', function () {
    return [['pharaonic'], ['laravel-settings'], ['pharaonic-config'], ['settings-config']];
});

dataset('service_provider_migrationTags', function () {
    return [['pharaonic'], ['laravel-settings'], ['pharaonic-migrations'], ['settings-migrations']];
});

test('it merges the configuration under pharaonic settings', function () {
    $this->assertSame('settings', config('pharaonic.settings.table'));
    $this->assertNull(config('pharaonic.settings.connection'));
    $this->assertSame('strict', config('pharaonic.settings.types.casting'));
    $this->assertTrue(config('pharaonic.settings.cache.enabled'));
    $this->assertNull(config('pharaonic.settings.cache.ttl'));
    $this->assertFalse(config('pharaonic.settings.models.purge_on_force_delete'));
    $this->assertSame([], config('pharaonic.settings.definitions'));
});

test('it loads the migration without publishing it', function () {
    $this->assertTrue(Schema::hasTable('settings'));
    $this->assertEqualsCanonicalizing(
        ['id', 'identifier', 'key', 'type', 'nullable', 'value', 'created_at', 'updated_at'],
        Schema::getColumnListing('settings')
    );
});

test('it publishes the configuration under its tags', function (string $tag) {
    $this->assertContains(config_path('pharaonic/settings.php'), ServiceProvider::pathsToPublish(SettingsServiceProvider::class, $tag));
})->with('service_provider_configTags');

test('it publishes the migration under its tags', function (string $tag) {
    $this->assertContains(database_path('migrations'), ServiceProvider::pathsToPublish(SettingsServiceProvider::class, $tag));
})->with('service_provider_migrationTags');

test('it registers the legacy migration command', function () {
    $this->assertArrayHasKey('settings:migrate-legacy', Artisan::all());
});

test('it defines the settings helper', function () {
    $this->assertTrue(function_exists('settings'));
    $this->assertInstanceOf(SettingBuilder::class, settings());
});
