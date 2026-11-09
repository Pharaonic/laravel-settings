# Maintaining This Package

This file records package-specific maintainer facts that are safe to keep in the public repository.

## Purpose

Typed application settings in the global scope, named scopes, or the scope of a saved Eloquent model, stored through the `Setting` Eloquent model and read with explicit, ordered fallback. Persisted Laravel configuration and `config()` loading belong to `pharaonic/laravel-dynamic-config`, not here; this package never writes the config repository.

## Supported Laravel lines

One branch per Laravel major, from `6.x` to the newest major. Each line's PHP range, `illuminate/*` constraint, and tools follow the Pharaonic Laravel line table. `6.x` runs PHPUnit; later lines run Pest. Releases `1.0.0`–`1.2.0` supported Laravel 6+, so the lines start at `6.x`.

`SettingType` and `CastingPolicy` are the one deliberate difference in package code between lines, decided by the maintainer on 2026-11-09: native string-backed enums from `10.x` (PHP 8.1+), and classes of string constants on `6.x`–`9.x`, whose PHP 8.0 has no enums. The case names, stored values, and accepted strings are the same everywhere, so `SettingType::INTEGER` and `'integer'` work on every line. What differs is what PHP sees: on `10.x`+ `$setting->type` is a `SettingType` case (an Eloquent enum cast) and type parameters are `SettingType|string`; below it is a string. A fix touching types is ported with this difference in mind.

## Core library

None. Everything the package does is Eloquent, cache, and validation glue.

## Public API invariants

Configuration keys (`pharaonic.settings.*`), publish tags, and registered Blade, Artisan, macro, and validation names are public API.

- `Setting::GLOBAL` is `'__global__'`, the stored identifier of the global scope. `null`, omitted, and the literal string are the same scope; the literal cannot name another scope.
- A model scope is `{getMorphClass()}:{getKey()}`, so `Setting::for($company)` and `Setting::for('company:12')` are the same rows. Changing this format, or an application changing its morph map, orphans stored model scopes. An unsaved model, or one without a string or integer key, throws `InvalidIdentifierException`.
- Identifiers and keys are 1–191 UTF-8 characters; keys have no empty dot segment. Dotted keys are exact strings, never nested arrays.
- `SettingBuilder` never changes an Eloquent method. The value methods (`getValue`, `getOwnValue`, `hasValue`, `hasOwnValue`, `setValue`, `setMany`, `forgetValue`, `allValues`, `allOwnValues`, `explainValue`, `retypeValue`, `fallbackTo`, `fallbackToGlobal`, `getIdentifier`, `getFallbackChain`) only run on a plain `for()` scope on the settings connection, optionally with `orderBy()`; anything else throws `UnscopedSettingQueryException`.
- A model made by a `for()` builder (`newModelInstance()`, so `make()`, `create()`, `firstOrNew()`, `firstOrCreate()`, `updateOrCreate()`, and the overridden `forceCreate()`) gets the scope's identifier and refuses, on `save()`, any other one (`InvalidIdentifierException`). Eloquent does not carry `where()` constraints into new models, so without this a scoped `create()` lands in the global scope or in an identifier from the attributes.
- `getValue()` falls back only through `fallbackTo()` scopes, in order; `fallbackToGlobal()` is `fallbackTo(null)`. A stored `null` stops the fallback. When no scope stores the key: the caller's default (closures are called), else the definition's default, else `null`.
- `explainValue()` returns exactly `key`, `found`, `value`, `source`, `inherited`, `is_default`, `type`, `nullable`.
- Values are JSON (`JSON_PRESERVE_ZERO_FRACTION`): null, bool, int, finite float, UTF-8 string, and arrays of them, up to 63 levels. JSON objects come back as arrays. Exception messages name the key, the scope, and types, never the value.
- A stored row's type and nullability change only through `retypeValue()` or `Setting::retype()`. `$setting->type` returns the `SettingType` case (`null` for an untyped first `null`); the `type` column stores its value. Type names: `string`, `integer`, `float`, `boolean`, `array`. A first `null` is stored with type `NULL` and `nullable` true.
- The `safe` policy converts exactly the cases in `TypeNormalizer`; widening them is a behavior change.
- Configuration: `pharaonic.settings.connection`, `.table`, `.types.casting`, `.cache.enabled`, `.cache.store`, `.cache.ttl`, `.models.purge_on_force_delete`, and `.definitions`, with the `SETTINGS_DB_CONNECTION`, `SETTINGS_DB_TABLE`, `SETTINGS_CACHE`, and `SETTINGS_CACHE_STORE` environment variables.
- Definition options: `type` (required), `nullable`, `default`, `casting`, `rules`. `encrypted` is refused until encryption ships; any other option is refused.
- Cache keys: `pharaonic:settings:2:` plus the SHA-1 of the JSON `[connection, table, identifier]`, then `:version` for the scope's version counter or `:{version}` for its rows. Changing them makes every application read its scopes from the database once more.
- Artisan: `settings:migrate-legacy` with `--apply`, `--duplicates=fail|latest`, `--ambiguous=fail|string|decode`, `--map=Type=prefix`, and `--backup=`.
- Publish tags: `pharaonic`, `laravel-settings`, `pharaonic-config`, `settings-config`, `pharaonic-migrations`, and `settings-migrations`.

## Package-specific constraints

- The migration file name `2021_02_01_000032_create_settings_table.php` and its class `CreateSettingsTable` are the 1.x ones on purpose: an application that ran 1.x never runs it again and converts its table with `settings:migrate-legacy`. The class is named because `6.x` supports Laravel below 8.37. Later schema changes go in new migrations.
- The migration refuses to run over an existing table. `settings:migrate-legacy` creates the same schema itself; keep the two in step.
- Uniqueness comes from the `(identifier, key)` index, never from a read before the write. A new key is inserted inside a savepoint (PostgreSQL aborts the transaction on a refused insert otherwise); a unique violation re-reads the winning row under `lockForUpdate()` and validates against it. A plain read decides between insert and update so a missing row takes no MySQL gap lock.
- On SQLite, a value write that opens the outermost transaction first runs `update {table} set id = id where 1 = 0` to take the write lock: otherwise two writers that both read first deadlock on the lock upgrade and SQLite fails one at once (`database is locked`) instead of waiting. Without it `ConcurrencyTest` failed 9 runs in 10 on SQLite; with it, 0 in 15. A caller's own transaction that reads first can still hit it.
- Every write validates in `Setting`'s `saving` hook, so `setValue()` and a native `save()` share one set of rules. Query-builder updates and raw SQL skip it and the cache.
- `UniqueConstraintViolationException` (Laravel 10) and `afterCommit()` (Laravel 8.17) are not used, because `6.x` lacks them. The cache listens to `TransactionCommitted` and `TransactionRolledBack` instead and runs pending invalidations at transaction level 0.
- No container state: `ScopeCache` keeps the pending invalidations of an open transaction in a static `WeakMap` keyed by the connection object, so Octane's per-request container clones cannot split them and nothing outlives the connection.
- Inside a transaction, reads bypass the cache and nothing is cached, so a transaction reads its own writes and never caches uncommitted rows.
- MySQL and MariaDB get `{charset}_bin` on `identifier` and `key`; other drivers compare case-sensitively already. SQL Server is not tested.
- `phpstan.neon.dist` also analyses `tests/Fixtures`: PHPStan analyses a trait only through a class that uses it.
- `ConcurrencyTest` runs separate PHP processes (`tests/Processes/worker.php`) against a shared database: a SQLite file by default, MySQL or PostgreSQL through the `SETTINGS_TEST_DB*` environment variables (`.github/workflows/databases.yml`).
- PHPDoc generics differ per line: `SettingBuilder` declares `@extends Builder<Setting>` only on lines whose Eloquent builder is generic.

## Branch notes

Fixes land on the oldest affected line and are ported to every later line and `develop`.

## Documentation

https://pharaonic.dev/packages/laravel/settings

Keep this file factual and package-specific. General Pharaonic engineering policy belongs in the private engineering repository, not here.
