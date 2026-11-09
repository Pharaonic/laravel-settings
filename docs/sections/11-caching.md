## Caching

Each scope's rows are cached as one entry: the global scope, every named scope, and every model scope separately. A [fallback chain](#fallback) reads each of its scopes from its own entry, so a change to the company scope is seen by every branch that falls back to it, without invalidating the branches.

```php title="config/pharaonic/settings.php"
'cache' => [
    'enabled' => env('SETTINGS_CACHE', true),
    'store' => env('SETTINGS_CACHE_STORE'), // null: the default store
    'ttl' => null,                          // seconds; null: until the next write
],
```

With the cache disabled, every value read queries the database.

### When the Cache Is Forgotten

`setValue()`, `setMany()`, `forgetValue()`, `retypeValue()`, a model `save()` or `delete()`, and a purging model delete forget the written scope only.

Every scope has a version counter in the cache, and its rows are cached under that version. A write moves the scope to a new version, so a request that loaded the old rows just before the write commits caches them under the old version, where no later read looks.

### Transactions

Inside a database transaction the cache is not used: reads go to the database, so you read your own writes, and nothing uncommitted is cached. The written scopes are forgotten only when the outermost transaction commits; a rollback forgets nothing, because nothing changed.

```php
DB::transaction(function () {
    settings()->setValue('theme', 'dark');
    settings()->getValue('theme'); // "dark", from the database
});
// Committed: other requests now read "dark".
```

`setMany()` runs in one transaction and forgets its scope once.

### Several Servers

Use a shared store (Redis, Memcached, database) when several servers or workers run the application, so a write on one server is seen by the others. A per-server store such as `array` or a local `file` store cannot invalidate the others' entries.

### Long-Running Processes

The package keeps no settings in memory between calls and no state in the container, so it is safe under Octane, queue workers and other long-running processes: every read comes from the cache store or the database. Invalidations waiting for a transaction belong to that connection object and are gone once it commits or rolls back.

:::warning Direct database writes
An `UPDATE` that does not go through the package is not seen by scopes already cached until the next package write to that scope, or until `ttl` expires. Set a `ttl` if other systems write the table.
:::
