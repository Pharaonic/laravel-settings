<?php

namespace Pharaonic\Laravel\Settings;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Settings\Console\MigrateLegacySettingsCommand;
use Pharaonic\Laravel\Settings\Support\ScopeCache;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/settings.php', 'pharaonic.settings');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Cache entries of a scope written inside a transaction are only
        // forgotten when the outermost transaction commits. ScopeCache keeps
        // no state of its own, so these listeners are safe under Octane.
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) {
            (new ScopeCache)->committed($event->connection);
        });

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
            (new ScopeCache)->rolledBack($event->connection);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/settings.php' => $this->app->configPath('pharaonic/settings.php'),
            ], ['pharaonic', 'laravel-settings', 'pharaonic-config', 'settings-config']);

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], ['pharaonic', 'laravel-settings', 'pharaonic-migrations', 'settings-migrations']);

            $this->commands([
                MigrateLegacySettingsCommand::class,
            ]);
        }
    }
}
