<?php

namespace Pharaonic\Laravel\Settings\Builders;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\CorruptSettingValueException;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Exceptions\InvalidSettingKeyException;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Exceptions\UnscopedSettingQueryException;
use Pharaonic\Laravel\Settings\Exceptions\UnsupportedSettingValueException;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Support\Definition;
use Pharaonic\Laravel\Settings\Support\Identifier;
use Pharaonic\Laravel\Settings\Support\ScopeCache;
use Pharaonic\Laravel\Settings\Support\UniqueViolation;
use Pharaonic\Laravel\Settings\Support\ValueCodec;

/**
 * The Eloquent builder of Setting. Everything Eloquent offers stays as it
 * is: get(), first(), where() and the rest return and filter Setting models.
 *
 * After for(), the value methods read and write typed values of that scope:
 * getValue(), setValue(), allValues() and the rest. They only run on a plain
 * for() scope, optionally with fallbackTo() scopes and orderBy(); any other
 * constraint throws UnscopedSettingQueryException rather than being ignored.
 */
class SettingBuilder extends Builder
{
    /**
     * How many times an insert that lost a race is retried.
     */
    private const ATTEMPTS = 3;

    /**
     * The identifier of the scope set by for().
     */
    private ?string $scopeIdentifier = null;

    /**
     * The fallback identifiers, in order.
     *
     * @var list<string>
     */
    private array $fallbacks = [];

    /**
     * Scope the query to the global scope (null or Setting::GLOBAL), a named
     * scope, or a saved model ("{morph class}:{key}").
     *
     * @throws InvalidIdentifierException
     */
    public function for(Model|string|null $identifier = null): static
    {
        if ($this->scopeIdentifier !== null) {
            throw new LogicException('The settings query is already scoped to ['.$this->scopeIdentifier.'].');
        }

        $this->scopeIdentifier = Identifier::resolve($identifier);
        $this->where($this->qualifyColumn('identifier'), $this->scopeIdentifier);

        return $this;
    }

    /**
     * Add a scope that value reads fall back to when no earlier scope stores
     * the key. Scopes are tried in the order they are added; a scope already
     * in the chain is skipped. Writes only ever touch the for() scope.
     *
     * @throws InvalidIdentifierException
     */
    public function fallbackTo(Model|string|null $identifier): static
    {
        $primary = $this->canonical();
        $fallback = Identifier::resolve($identifier);

        if ($fallback !== $primary && ! in_array($fallback, $this->fallbacks, true)) {
            $this->fallbacks[] = $fallback;
        }

        return $this;
    }

    /**
     * A model made by a for() scope, through make(), create(), firstOrNew(),
     * firstOrCreate(), updateOrCreate() or forceCreate(), belongs to that
     * scope, and saving it with another identifier throws. Eloquent does not
     * carry where() constraints into new models, so without this a scoped
     * create() would land in the global scope, or in any identifier passed as
     * an attribute.
     *
     * @param  array<string, mixed>  $attributes
     * @return Setting
     *
     * @throws InvalidIdentifierException when the attributes name another scope
     */
    public function newModelInstance($attributes = [])
    {
        if ($this->scopeIdentifier === null) {
            return self::setting(parent::newModelInstance($attributes));
        }

        $given = $attributes['identifier'] ?? null;

        if ($given !== null && $given !== $this->scopeIdentifier) {
            throw Setting::otherScope($this->scopeIdentifier);
        }

        $attributes['identifier'] = $this->scopeIdentifier;
        $model = self::setting(parent::newModelInstance($attributes));
        $model->restrictToScope($this->scopeIdentifier);

        return $model;
    }

    /**
     * Create through this builder, so a for() scope applies; Eloquent's own
     * forceCreate() creates through a new, unscoped query.
     *
     * @param  array<string, mixed>  $attributes
     * @return Setting
     */
    public function forceCreate(array $attributes)
    {
        $model = $this->newModelInstance();
        $model->forceFill($attributes)->save();

        return $model;
    }

    /**
     * Add the global scope as the next fallback; the same as fallbackTo(null).
     */
    public function fallbackToGlobal(): static
    {
        return $this->fallbackTo(Setting::GLOBAL);
    }

    /**
     * The identifier set by for(), or null before it.
     */
    public function getIdentifier(): ?string
    {
        return $this->scopeIdentifier;
    }

    /**
     * The identifiers value reads try, in order.
     *
     * @return list<string>
     */
    public function getFallbackChain(): array
    {
        return array_merge([$this->canonical()], $this->fallbacks);
    }

    /**
     * The value from the first scope of the chain that stores the key. A
     * stored null counts as stored. When no scope stores it: the given
     * default (a closure is called), else the definition's default, else null.
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $found = $this->locate($key, $this->getFallbackChain());

        if ($found !== null) {
            return $found['value'];
        }

        return func_num_args() > 1 ? value($default) : $this->definitionDefault($key);
    }

    /**
     * The value stored in the for() scope only, ignoring fallbacks.
     */
    public function getOwnValue(string $key, mixed $default = null): mixed
    {
        $found = $this->locate($key, [$this->canonical()]);

        if ($found !== null) {
            return $found['value'];
        }

        return func_num_args() > 1 ? value($default) : $this->definitionDefault($key);
    }

    /**
     * Whether a scope of the chain stores the key (a definition's default
     * does not count).
     */
    public function hasValue(string $key): bool
    {
        return $this->locate($key, $this->getFallbackChain()) !== null;
    }

    /**
     * Whether the for() scope stores the key.
     */
    public function hasOwnValue(string $key): bool
    {
        return $this->locate($key, [$this->canonical()]) !== null;
    }

    /**
     * Every stored value of the chain, keyed by its exact key in string
     * order; for each key the earliest scope wins. Definition defaults are
     * not included.
     *
     * @return array<string, mixed>
     */
    public function allValues(): array
    {
        return $this->collect($this->getFallbackChain());
    }

    /**
     * Every value stored in the for() scope, keyed by its exact key in string
     * order.
     *
     * @return array<string, mixed>
     */
    public function allOwnValues(): array
    {
        return $this->collect([$this->canonical()]);
    }

    /**
     * Where the value of the key comes from: the scope that stores it, or
     * the definition's default when none does.
     *
     * @return array{key: string, found: bool, value: mixed, source: string|null, inherited: bool, is_default: bool, type: string|null, nullable: bool|null}
     */
    public function explainValue(string $key): array
    {
        $chain = $this->getFallbackChain();
        $found = $this->locate($key, $chain);

        if ($found !== null) {
            return [
                'key' => $key,
                'found' => true,
                'value' => $found['value'],
                'source' => $found['identifier'],
                'inherited' => $found['identifier'] !== $chain[0],
                'is_default' => false,
                'type' => $found['type'],
                'nullable' => $found['nullable'],
            ];
        }

        $definition = Definition::find($key);

        return [
            'key' => $key,
            'found' => false,
            'value' => $definition !== null && $definition->hasDefault ? $definition->default : null,
            'source' => null,
            'inherited' => false,
            'is_default' => $definition !== null && $definition->hasDefault,
            'type' => $definition?->type->value,
            'nullable' => $definition !== null ? $definition->nullable : null,
        ];
    }

    /**
     * Store the value of the key in the for() scope, right away, and return
     * the saved row.
     *
     * A new key takes its type from $type, else its definition, else the
     * value (a first null leaves it untyped until the first non-null value).
     * A stored key keeps its type and nullability: $type and $nullable must
     * match them, and changing them is retypeValue()'s job.
     *
     *
     * @throws SettingTypeMismatchException
     * @throws UnsupportedSettingValueException
     * @throws SettingDefinitionException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function setValue(string $key, mixed $value, SettingType|string|null $type = null, ?bool $nullable = null): Setting
    {
        $identifier = $this->canonical();
        Identifier::key($key);

        $type = $type === null ? null : SettingType::parse($type);

        return $this->transaction(function () use ($identifier, $key, $value, $type, $nullable): Setting {
            return $this->write($identifier, $key, $value, $type, $nullable);
        });
    }

    /**
     * Store several values of the for() scope in one transaction: every value
     * is stored, or none is. $values maps each key to its value; types come
     * from the stored rows, definitions or the values, as with setValue().
     *
     * @param  array<array-key, mixed>  $values
     *
     * @throws InvalidSettingKeyException for an integer key, as a list would have
     */
    public function setMany(array $values): void
    {
        $identifier = $this->canonical();

        foreach (array_keys($values) as $key) {
            if (! is_string($key)) {
                throw new InvalidSettingKeyException('setMany() takes an array keyed by setting keys; an integer key such as ['.$key.'] is refused, use setValue() for it.');
            }

            Identifier::key($key);
        }

        $this->transaction(function () use ($identifier, $values): void {
            foreach ($values as $key => $value) {
                $this->write($identifier, (string) $key, $value, null, null);
            }
        });
    }

    /**
     * Delete the key from the for() scope. True when a row was deleted.
     */
    public function forgetValue(string $key): bool
    {
        $identifier = $this->canonical();
        Identifier::key($key);

        return $this->transaction(function () use ($identifier, $key): bool {
            $row = $this->row($identifier, $key, true);

            return $row !== null && $row->delete() === true;
        });
    }

    /**
     * Change the type and nullability of the key stored in the for() scope,
     * converting its value with the safe casting rules, under a row lock.
     *
     *
     * @throws SettingTypeMismatchException when the value cannot be converted
     * @throws SettingDefinitionException when the key's definition declares other metadata
     */
    public function retypeValue(string $key, SettingType|string $type, ?bool $nullable = null): Setting
    {
        $identifier = $this->canonical();
        Identifier::key($key);

        return $this->transaction(function () use ($identifier, $key, $type, $nullable): Setting {
            $row = $this->row($identifier, $key, true);

            if ($row === null) {
                throw new InvalidArgumentException("The setting [{$key}] of the [{$identifier}] scope is not stored, so it cannot be retyped.");
            }

            return $row->retype($type, $nullable);
        });
    }

    /**
     * Insert the row, or update it under a row lock. A plain read decides
     * which, so a missing row takes no gap lock; an insert that loses the
     * race to another writer updates the row that won instead.
     */
    private function write(string $identifier, string $key, mixed $value, ?SettingType $type, ?bool $nullable): Setting
    {
        for ($attempt = 1; ; $attempt++) {
            if ($this->row($identifier, $key, false) === null) {
                $setting = new Setting;
                $setting->identifier = $identifier;
                $setting->key = $key;
                $setting->type = $type;
                $setting->setAttribute('nullable', $nullable);
                $setting->value = $value;

                try {
                    // A savepoint keeps a refused insert from aborting the
                    // transaction (PostgreSQL aborts it otherwise).
                    $this->connection()->transaction(function () use ($setting): void {
                        $setting->save();
                    });

                    return $setting;
                } catch (QueryException $exception) {
                    if (! UniqueViolation::matches($exception) || $attempt >= self::ATTEMPTS) {
                        throw $exception;
                    }
                }
            }

            $row = $this->row($identifier, $key, true);

            // Deleted since the read: insert again.
            if ($row === null) {
                continue;
            }

            if ($type !== null) {
                $row->type = $type;
            }

            if ($nullable !== null) {
                $row->setAttribute('nullable', $nullable);
            }

            $row->value = $value;
            $row->save();

            return $row;
        }
    }

    /**
     * The stored row of the key, locked for update when asked.
     */
    private function row(string $identifier, string $key, bool $lock): ?Setting
    {
        $query = Setting::query()
            ->where('identifier', $identifier)
            ->where('key', $key);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row instanceof Setting ? $row : null;
    }

    /**
     * The decoded value of the key from the first scope that stores it.
     *
     * @param  list<string>  $chain
     * @return array{identifier: string, value: mixed, type: string|null, nullable: bool}|null
     */
    private function locate(string $key, array $chain): ?array
    {
        Identifier::key($key);

        foreach ($chain as $identifier) {
            $rows = $this->rows($identifier, $key);

            if (array_key_exists($key, $rows)) {
                return ['identifier' => $identifier, 'value' => $this->decode($identifier, $key, $rows[$key])] + $rows[$key];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $chain
     * @return array<string, mixed>
     */
    private function collect(array $chain): array
    {
        $values = [];

        foreach (array_reverse($chain) as $identifier) {
            foreach ($this->rows($identifier, null) as $key => $row) {
                $values[$key] = $this->decode($identifier, $key, $row);
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * The rows of a scope, keyed by key: every row from the cache, or only
     * the key's row when the cache is off or a transaction is open.
     *
     * @return array<string, array{type: string|null, nullable: bool, value: string}>
     */
    private function rows(string $identifier, ?string $key): array
    {
        $connection = $this->connection();
        $cache = new ScopeCache;
        $only = $cache->usable($connection) ? null : $key;

        return $cache->rows($connection, (new Setting)->getTable(), $identifier, function () use ($identifier, $only): array {
            $query = Setting::query()->toBase()
                ->where('identifier', $identifier)
                ->orderBy('key');

            if ($only !== null) {
                $query->where('key', $only);
            }

            $rows = [];

            foreach ($query->get(['key', 'type', 'nullable', 'value']) as $row) {
                $row = (array) $row;
                $type = $row['type'] ?? null;
                $value = $row['value'] ?? null;

                $rows[is_scalar($row['key']) ? (string) $row['key'] : ''] = [
                    'type' => is_string($type) && $type !== '' ? $type : null,
                    'nullable' => (bool) ($row['nullable'] ?? false),
                    'value' => is_string($value) ? $value : 'null',
                ];
            }

            return $rows;
        });
    }

    /**
     * @param  array{type: string|null, nullable: bool, value: string}  $row
     *
     * @throws CorruptSettingValueException
     * @throws SettingDefinitionException
     */
    private function decode(string $identifier, string $key, array $row): mixed
    {
        $definition = Definition::find($key);

        if ($definition !== null) {
            if ($row['type'] !== null && $row['type'] !== $definition->type->value) {
                throw SettingDefinitionException::conflict($key, $identifier, 'type', $row['type'], $definition->type->value);
            }

            if ($row['nullable'] !== $definition->nullable) {
                throw SettingDefinitionException::conflict($key, $identifier, 'nullability', $row['nullable'] ? 'nullable' : 'not nullable', $definition->nullable ? 'nullable' : 'not nullable');
            }
        }

        return ValueCodec::decode($row['value'], $key, $identifier);
    }

    private function definitionDefault(string $key): mixed
    {
        $definition = Definition::find($key);

        return $definition !== null && $definition->hasDefault ? $definition->default : null;
    }

    /**
     * The identifier of a plain for() scope.
     *
     * @throws UnscopedSettingQueryException
     */
    private function canonical(): string
    {
        if ($this->scopeIdentifier === null) {
            throw new UnscopedSettingQueryException('Call for() before using the settings value methods: Setting::for($identifier)->getValue($key).');
        }

        $query = $this->getQuery();

        if (count($query->wheres) !== 1
            || ! empty($query->joins)
            || ! empty($query->groups)
            || ! empty($query->havings)
            || ! empty($query->unions)
            || $query->limit !== null
            || $query->offset !== null
            || $query->columns !== null
            || $query->distinct !== false
            || $query->lock !== null
            || $this->eagerLoad !== []
            || $this->scopes !== []
            || $query->getConnection() !== $this->connection()) {
            throw new UnscopedSettingQueryException('The settings value methods only run on a plain for() scope; use get() for a query with other constraints.');
        }

        return $this->scopeIdentifier;
    }

    /**
     * Run the callback in a transaction of the settings connection; deadlocks
     * retry it when no outer transaction is open.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function transaction(callable $callback): mixed
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $callback) {
            // SQLite has one writer. Two transactions that both read before
            // writing deadlock on the lock upgrade, and SQLite fails one at
            // once instead of waiting. Taking the write lock first, with an
            // update that matches nothing, makes the others wait their turn.
            if ($connection->getDriverName() === 'sqlite' && $connection->transactionLevel() === 1) {
                $table = $connection->getQueryGrammar()->wrapTable((new Setting)->getTable());

                $connection->update("update {$table} set id = id where 1 = 0");
            }

            return $callback();
        }, self::ATTEMPTS);
    }

    /**
     * The model Eloquent made for this builder, which is always a Setting.
     */
    private static function setting(object $model): Setting
    {
        if (! $model instanceof Setting) {
            throw new LogicException('SettingBuilder only makes Setting models.');
        }

        return $model;
    }

    /**
     * The settings connection, which every value method reads and writes.
     */
    private function connection(): Connection
    {
        return (new Setting)->getConnection();
    }
}
