<?php

namespace Pharaonic\Laravel\Settings\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\SettingsServiceProvider;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_merges_the_configuration_under_pharaonic_settings(): void
    {
        $this->assertSame('settings', config('pharaonic.settings.table'));
        $this->assertNull(config('pharaonic.settings.connection'));
        $this->assertSame('strict', config('pharaonic.settings.types.casting'));
        $this->assertTrue(config('pharaonic.settings.cache.enabled'));
        $this->assertNull(config('pharaonic.settings.cache.ttl'));
        $this->assertFalse(config('pharaonic.settings.models.purge_on_force_delete'));
        $this->assertSame([], config('pharaonic.settings.definitions'));
    }

    public function test_it_loads_the_migration_without_publishing_it(): void
    {
        $this->assertTrue(Schema::hasTable('settings'));
        $this->assertEqualsCanonicalizing(
            ['id', 'identifier', 'key', 'type', 'nullable', 'value', 'created_at', 'updated_at'],
            Schema::getColumnListing('settings')
        );
    }

    /**
     * @dataProvider configTags
     */
    public function test_it_publishes_the_configuration_under_its_tags(string $tag): void
    {
        $this->assertContains(config_path('pharaonic/settings.php'), ServiceProvider::pathsToPublish(SettingsServiceProvider::class, $tag));
    }

    /**
     * @return array<int, array{string}>
     */
    public static function configTags(): array
    {
        return [['pharaonic'], ['laravel-settings'], ['pharaonic-config'], ['settings-config']];
    }

    /**
     * @dataProvider migrationTags
     */
    public function test_it_publishes_the_migration_under_its_tags(string $tag): void
    {
        $this->assertContains(database_path('migrations'), ServiceProvider::pathsToPublish(SettingsServiceProvider::class, $tag));
    }

    /**
     * @return array<int, array{string}>
     */
    public static function migrationTags(): array
    {
        return [['pharaonic'], ['laravel-settings'], ['pharaonic-migrations'], ['settings-migrations']];
    }

    public function test_it_registers_the_legacy_migration_command(): void
    {
        $this->assertArrayHasKey('settings:migrate-legacy', Artisan::all());
    }

    public function test_it_defines_the_settings_helper(): void
    {
        $this->assertTrue(function_exists('settings'));
        $this->assertInstanceOf(SettingBuilder::class, settings());
    }
}
