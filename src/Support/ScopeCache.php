<?php

namespace Pharaonic\Laravel\Settings\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use WeakMap;

/**
 * Caches the rows of each scope as one entry, never a resolved fallback
 * result, so a scope that falls back to another sees that scope's changes as
 * soon as its own entry is fresh.
 *
 * Every scope has a version counter in the cache, and its rows live under a
 * key that includes that version. A write bumps the version, so a reader
 * that loaded old rows just before the write commits stores them under the
 * old version, where no later reader looks. Inside a database transaction
 * nothing is read from or written to the cache, and versions are only bumped
 * once the outermost transaction commits; a rollback drops them.
 *
 * The pending bumps of an open transaction belong to its connection object,
 * not to this class or the container: under Octane the provider's listeners
 * and a request can resolve different instances, and a WeakMap entry goes
 * away with its connection instead of leaking into the next request.
 *
 * @internal
 */
final class ScopeCache
{
    /**
     * Pending bumps by connection, keyed by the scope's base cache key.
     *
     * @var WeakMap<ConnectionInterface, array<string, true>>|null
     */
    private static ?WeakMap $pending = null;

    /**
     * The rows of the scope, loaded by the callback when they are not cached.
     *
     * @param  Closure(): array<string, array{type: string|null, nullable: bool, value: string}>  $load
     * @return array<string, array{type: string|null, nullable: bool, value: string}>
     */
    public function rows(ConnectionInterface $connection, string $table, string $identifier, Closure $load): array
    {
        if (! $this->usable($connection)) {
            return $load();
        }

        $base = $this->baseKey($connection, $table, $identifier);
        $ttl = Config::get('pharaonic.settings.cache.ttl');

        /** @var array<string, array{type: string|null, nullable: bool, value: string}> */
        return $this->store()->remember($base.':'.$this->version($base), is_numeric($ttl) ? (int) $ttl : null, $load);
    }

    /**
     * Whether a read goes through the cache: caching is enabled and the
     * connection is not inside a transaction, whose writes are not committed.
     */
    public function usable(ConnectionInterface $connection): bool
    {
        return $this->enabled() && $connection->transactionLevel() === 0;
    }

    /**
     * Forget the scope's rows, once the outermost transaction commits.
     */
    public function invalidate(ConnectionInterface $connection, string $table, string $identifier): void
    {
        if (! $this->enabled()) {
            return;
        }

        $base = $this->baseKey($connection, $table, $identifier);

        if ($connection->transactionLevel() > 0) {
            $pending = self::pending();
            $pending[$connection] = ($pending[$connection] ?? []) + [$base => true];

            return;
        }

        $this->bump($base);
    }

    /**
     * Run the bumps of a connection whose outermost transaction committed.
     */
    public function committed(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            return;
        }

        $pending = self::pending();
        $bases = $pending[$connection] ?? [];
        unset($pending[$connection]);

        foreach (array_keys($bases) as $base) {
            $this->bump($base);
        }
    }

    /**
     * Drop the bumps of a connection whose outermost transaction rolled back:
     * nothing it wrote reached the database. A rollback to a savepoint keeps
     * them, so the outer commit still forgets the scope.
     */
    public function rolledBack(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() === 0) {
            unset(self::pending()[$connection]);
        }
    }

    /**
     * @return WeakMap<ConnectionInterface, array<string, true>>
     */
    private static function pending(): WeakMap
    {
        if (self::$pending === null) {
            /** @var WeakMap<ConnectionInterface, array<string, true>> $pending */
            $pending = new WeakMap;
            self::$pending = $pending;
        }

        return self::$pending;
    }

    private function version(string $base): string
    {
        $store = $this->store();
        $version = $store->get($base.':version');

        if (! is_int($version) && ! is_string($version)) {
            // A random start, so a version lost from the cache never brings
            // back rows cached under an earlier one.
            $store->add($base.':version', random_int(1, PHP_INT_MAX >> 1), $this->versionTtl());
            $version = $store->get($base.':version');
        }

        return is_int($version) || is_string($version) ? (string) $version : '0';
    }

    private function bump(string $base): void
    {
        $store = $this->store();
        $old = $store->get($base.':version');

        $store->put($base.':version', random_int(1, PHP_INT_MAX >> 1), $this->versionTtl());

        if (is_int($old) || is_string($old)) {
            $store->forget($base.':'.$old);
        }
    }

    /**
     * Versions live as long as rows do, or forever when rows do.
     */
    private function versionTtl(): ?int
    {
        $ttl = Config::get('pharaonic.settings.cache.ttl');

        return is_numeric($ttl) ? (int) $ttl * 2 : null;
    }

    private function baseKey(ConnectionInterface $connection, string $table, string $identifier): string
    {
        // Hashing the connection, table and identifier keeps keys store-safe
        // and keeps the global scope apart from every other identifier.
        return 'pharaonic:settings:2:'.sha1((string) json_encode([$this->connectionName($connection), $table, $identifier]));
    }

    private function connectionName(ConnectionInterface $connection): string
    {
        return method_exists($connection, 'getName') && is_string($name = $connection->getName()) ? $name : 'default';
    }

    private function enabled(): bool
    {
        return filter_var(Config::get('pharaonic.settings.cache.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function store(): Repository
    {
        $store = Config::get('pharaonic.settings.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
