<p align="center"><a href="https://pharaonic.dev/packages/laravel/settings" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-settings/develop/docs/cover.webp"></a></p>

<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.3%20|%208.4%20|%208.5&color=blue&style=flat-square" alt="PHP Version : 8.3 | 8.4 | 8.5"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=13.x&color=F05340&style=flat-square" alt="Laravel Version : 13.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-settings/actions/workflows/build.yml/badge.svg?branch=13.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-settings" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-settings&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-settings" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-settings/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-settings" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-settings/downloads" alt="Packagist Downloads"></a>
</p>

<h3 align="center">Typed settings for Laravel, global, named, or per model, stored through an Eloquent Setting model.</h3>
<br>

## Installation

```bash
composer require pharaonic/laravel-settings
php artisan migrate
```

The service provider is registered automatically, and `migrate` creates the `settings` table. An application upgrading from 1.x converts its existing table with `php artisan settings:migrate-legacy` instead (a dry run until `--apply`). Publish the configuration to change the table, cache, casting policy, or definitions:

```bash
php artisan vendor:publish --tag=settings-config
```

## Usage

```php
use Pharaonic\Laravel\Settings\Concerns\HasSettings;

class Company extends Model
{
    use HasSettings;
}
```

```php
settings()->setValue('site.name', 'Pharaonic');      // global, type string
settings('checkout')->setValue('minimum', 100);      // named scope, type integer
$company->setSetting('timezone', 'Africa/Cairo');    // model scope, written right away

settings()->getValue('site.name');                   // 'Pharaonic'
settings('checkout')->setValue('minimum', '100');    // SettingTypeMismatchException: stays an integer

$theme = settings($branch)
    ->fallbackTo($company)
    ->fallbackToGlobal()                             // then the global scope
    ->getValue('theme', 'light');

Setting::for($company)->where('type', 'string')->get(); // plain Eloquent: Setting models
```

## Documentation

Full documentation: [Laravel Settings Documentation](https://pharaonic.dev/packages/laravel/settings).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
