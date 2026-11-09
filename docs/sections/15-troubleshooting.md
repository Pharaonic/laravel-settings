## Troubleshooting

### `SettingTypeMismatchException` on a value that looks right

The setting already has a type, and the strict policy only accepts that exact PHP type: `"20"` is not an integer. Pass the right type, enable `safe` casting for the key, or change the type with `retypeValue()`.

### `UnscopedSettingQueryException`

A value method ran on a builder without `for()`, or with `where()`, `limit()`, `select()` or another constraint. Use `settings($scope)->getValue()` for values, and `get()` for filtered rows.

### `The [settings] table already exists` during `migrate`

The table comes from 1.x or from another package. Convert a 1.x table with `php artisan settings:migrate-legacy`; otherwise set `pharaonic.settings.table` to another name.

### `SettingDefinitionException: ... is stored with the type`

A definition was added for a key that is stored with another type or nullability. Run `retypeValue($key, $type, $nullable)` in each scope that stores it.

### Model settings disappeared after adding a morph map

The model scope is now `alias:id` instead of `App\Models\Company:id`. Remove the alias, or rename the stored identifiers: `Setting::query()->where('identifier', 'like', 'App\Models\Company:%')` and update them to the alias (then clear the cache).

### `database is locked` on SQLite

SQLite allows one writer at a time. The package's own writes take the write lock before reading, so concurrent writes wait their turn. Your own `DB::transaction()` that reads first and then calls `setValue()` can still collide with another writer: write before you read in that transaction, retry it with `DB::transaction($callback, 3)`, or use MySQL or PostgreSQL for concurrent writers.

### A change made in the database directly is not visible

Scopes are cached until the next package write to them. Clear the cache, set `cache.ttl`, or write through the package.

### `$company->settings` throws `LogicException`

`settings()` is a method that returns a builder, not a relation. Call `$company->settings()` or `$company->getSetting()`.

### Validation errors are under `value`

Rules run on the value alone, so errors use the `value` key; the message names the setting key, such as "The checkout.minimum_order must be at least 0."
