# Changelog

All notable changes to this project are documented here.

## 12.0.0 - Unreleased

First release of the Laravel 12 line, built from 11.0.0.

### Compatibility

- Laravel 12 on PHP 8.2, 8.3, 8.4 and 8.5.
- The same API and behavior as 11.0.0.

## 11.0.0 - Unreleased

First release of the Laravel 11 line, built from 10.0.0.

### Compatibility

- Laravel 11 on PHP 8.2, 8.3 and 8.4.
- The same API and behavior as 10.0.0.

## 10.0.0 - Unreleased

First release of the Laravel 10 line, built from 9.0.0.

### Changed

- `SettingType` and `CastingPolicy` are native string-backed enums, with the same case names and values. Every method that takes a type (`setValue()`, `retypeValue()`, `Setting::retype()`, `setSetting()`) accepts the case or its value, and definitions and `pharaonic.settings.types.casting` accept the cases too.

### Behavior Changes from 9.0.0

- `$setting->type` returns a `SettingType` case instead of a string (`null` is unchanged). Compare it with `SettingType::INTEGER`, or read `$setting->type->value`. The stored column and `explainValue()['type']` stay strings.
- `SettingType::all()` and `isValid()`, and `CastingPolicy::isValid()`, are replaced by the native `cases()` and `tryFrom()`. `SettingType::of()` returns a case instead of a string; `SettingType::parse()` and `rule()` are new.

### Compatibility

- Laravel 10 on PHP 8.1, 8.2 and 8.3.

## 9.0.0 - Unreleased

First release of the Laravel 9 line, built from 8.0.0.

### Compatibility

- Laravel 9.33+ on PHP 8.0, 8.1 and 8.2. 9.33 is the first 9.x release that requires a Carbon release working on PHP 8.2; older releases install Carbon 2.53, which fails there.
- The same API and behavior as 8.0.0.

## 8.0.0 - Unreleased

First release of the Laravel 8 line, built from 7.0.0.

### Compatibility

- Laravel 8.75+ on PHP 8.0 and 8.1.
- The same API and behavior as 7.0.0.

## 7.0.0 - Unreleased

First release of the Laravel 7 line, built from 6.0.0.

### Compatibility

- Laravel 7.30+ on PHP 8.0.
- The same API and behavior as 6.0.0.

## 6.0.0 - Unreleased

A rewrite. The 1.x classes are removed, and a 1.x table is converted with `php artisan settings:migrate-legacy`.

### Added

- The `Setting` Eloquent model with its `SettingBuilder`: `Setting::for($identifier)` scopes a query to the global scope (`null` or `Setting::GLOBAL`, stored as `__global__`), a named scope, or a saved model (`{morph class}:{key}`, honoring the morph map). `get()`, `where()` and the rest stay plain Eloquent.
- Value methods on a `for()` scope: `getValue()`, `getOwnValue()`, `hasValue()`, `hasOwnValue()`, `setValue()`, `setMany()`, `forgetValue()`, `allValues()`, `allOwnValues()`, `explainValue()`, and `retypeValue()`. They refuse a query with other constraints instead of ignoring them.
- Typed values: every row stores its type (`string`, `integer`, `float`, `boolean`, `array`) and nullability. A new key takes its type from an explicit argument, its definition, or its first non-null value; a write never changes it, and `retypeValue()` changes it deliberately. A first `null` stays untyped until the first value.
- The `strict` casting policy (the default) and an opt-in `safe` policy that converts only unambiguous values, such as `"20"` for an integer or `"false"` for a boolean.
- Explicit fallback with `fallbackTo()` and `fallbackToGlobal()`: a missing key moves on to the next scope, a stored `null` stops there.
- Optional definitions in `pharaonic.settings.definitions`: type, nullability, default, casting policy, and Laravel validation rules for a key in every scope.
- The `HasSettings` trait: `settings()`, `getSetting()`, `setSetting()`, `hasSetting()`, `forgetSetting()`, and `allSettings()`. Settings are written right away, never on the model's save, and kept on a soft delete; `pharaonic.settings.models.purge_on_force_delete` removes them on a permanent delete.
- A per-scope cache, forgotten only after the surrounding transaction commits, with `pharaonic.settings.cache.enabled`, `.store`, and `.ttl`.
- `settings:migrate-legacy`, which converts a 1.x table side by side: a dry run by default, explicit policies for duplicate keys, values that may be JSON or text, and unknown model types, a kept backup table, and a checksum of the result.
- A unique `(identifier, key)` index, with binary collation on MySQL and MariaDB so keys are case-sensitive.
- `pharaonic.settings.connection` and `pharaonic.settings.table`.

### Removed

- `Classes\Settings`, `Models\Settings`, and `Traits\Settingable`. Replace `Settingable` with `HasSettings`, `Models\Settings` with `Models\Setting`, `settings()->get()` and `$model->settings->...` with `getValue()` and `getSetting()`, and `save()` with nothing: writes are immediate.
- The `settings()` helper no longer returns a cached value bag. It returns `Setting::for($identifier)`, and is only defined when no other `settings()` function exists.

### Compatibility

- Laravel 6 on PHP 8.0.
- The migration file keeps its 1.x name and creates the 2 schema, so an application that already ran it never runs it again. It refuses to run over an existing table.
- Encrypted settings are not supported yet: a definition with `encrypted` is refused rather than stored as plaintext.
