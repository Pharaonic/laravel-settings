## Installation

Install the package with Composer and create its table.

### Requirements

- PHP 8.0, 8.1 or 8.2
- Laravel 9.x

### Composer Installation

```bash title="Terminal" no-line-numbers
composer require pharaonic/laravel-settings
```

The service provider is registered automatically. It also defines the `settings()` helper, unless your application or another package already defines a function with that name.

### Run Migrations

The package loads its migration automatically, so the `settings` table is created with your other migrations:

```bash title="Terminal" no-line-numbers
php artisan migrate
```

To change the migration before it runs, publish it into `database/migrations`:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=settings-migrations
```

:::warning Upgrading from 1.x
If your application already has the `settings` table from version 1.x, `migrate` does not touch it: the migration keeps its 1.x file name, so it never runs again. Convert the table with `php artisan settings:migrate-legacy`, as described in [Upgrading from 1.x](#upgrading).
:::

### Publish Configuration

Publish the configuration when you need another connection or table, a cache setup, the safe casting policy, or definitions:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=settings-config
```

The file is published to `config/pharaonic/settings.php`.

:::success Installation Complete
You're all set! Store your first value with `settings()->setValue('site.name', 'My Site')`.
:::
