<?php

namespace Pharaonic\Laravel\Settings;

use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Migration Loading
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Publishes
        $this->publishes([
            __DIR__ . '/database/migrations/2021_02_01_000032_create_settings_table.php'             => database_path('migrations/2021_02_01_000032_create_settings_table.php'),
        ], ['pharaonic', 'laravel-settings']);
    }
}
