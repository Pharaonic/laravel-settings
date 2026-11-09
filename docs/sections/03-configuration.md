## Configuration

Every option lives in `config/pharaonic/settings.php` and is read as `pharaonic.settings.*`.

```php title="config/pharaonic/settings.php"
return [
    'connection' => env('SETTINGS_DB_CONNECTION'),
    'table' => env('SETTINGS_DB_TABLE', 'settings'),

    'types' => [
        'casting' => 'strict', // strict or safe
    ],

    'cache' => [
        'enabled' => env('SETTINGS_CACHE', true),
        'store' => env('SETTINGS_CACHE_STORE'),
        'ttl' => null,
    ],

    'models' => [
        'purge_on_force_delete' => false,
    ],

    'definitions' => [],
];
```

| Option | Default | Description |
| --- | --- | --- |
| `connection` | `null` | The database connection of the table. `null` uses the default connection. |
| `table` | `settings` | The table name. The migration and every query use it. |
| `types.casting` | `strict` | How a value that is not already of a setting's type is handled. See [Typed Values](#typed-values). |
| `cache.enabled` | `true` | Cache each scope's rows. |
| `cache.store` | `null` | The cache store. `null` uses the default store. |
| `cache.ttl` | `null` | Seconds an entry lives. `null` keeps it until the next write to the scope. |
| `models.purge_on_force_delete` | `false` | Delete a model's settings when `HasSettings` sees it deleted permanently. |
| `definitions` | `[]` | Optional definitions of important keys. See [Definitions and Validation](#definitions). |

### The Global Scope

The global scope is stored as the identifier `__global__`, available as `Setting::GLOBAL`. It is not configurable: changing it would orphan every global row.

```php
use Pharaonic\Laravel\Settings\Models\Setting;

Setting::for();                // global
Setting::for(null);            // global
Setting::for(Setting::GLOBAL); // global
Setting::for('__global__');    // global, the same string
```

:::warning Reserved identifier
PHP cannot tell `Setting::GLOBAL` from the string `'__global__'`, so that string always means the global scope and cannot name any other scope.
:::

:::tip Config cache
Keep the file free of closures and objects. Definitions are plain arrays, so `php artisan config:cache` works.
:::
