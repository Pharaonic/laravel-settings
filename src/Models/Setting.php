<?php

namespace Pharaonic\Laravel\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Support\Definition;
use Pharaonic\Laravel\Settings\Support\Identifier;
use Pharaonic\Laravel\Settings\Support\ScopeCache;
use Pharaonic\Laravel\Settings\Support\TypeNormalizer;
use Pharaonic\Laravel\Settings\Support\ValueCodec;
use Throwable;

/**
 * One stored setting: a key in one scope, with its type, nullability and
 * value. `$setting->value` is the typed PHP value; the stored JSON is
 * `$setting->getAttributes()['value']`.
 *
 * Every save checks the row like setValue() does: the type and nullability
 * of a stored row never change except through retype(), values are checked
 * (or safely cast) against the type, and definitions and their rules apply.
 * The scope's cache is forgotten after the save or delete commits. Mass
 * updates through the query builder skip all of this.
 *
 * @property int $id
 * @property string $identifier
 * @property string $key
 * @property string|null $type
 * @property bool $nullable
 * @property mixed $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static SettingBuilder for(Model|string|null $identifier = null)
 * @method static SettingBuilder query()
 */
class Setting extends Model
{
    /**
     * The identifier of the global scope. Setting::for(), Setting::for(null)
     * and Setting::for('__global__') are the same scope, so "__global__"
     * cannot name any other scope.
     */
    public const GLOBAL = '__global__';

    /**
     * The validation rule of each type.
     */
    private const TYPE_RULES = [
        SettingType::STRING => 'string',
        SettingType::INTEGER => 'integer',
        SettingType::FLOAT => 'numeric',
        SettingType::BOOLEAN => 'boolean',
        SettingType::ARRAY => 'array',
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = ['identifier', 'key', 'type', 'nullable', 'value'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'nullable' => 'boolean',
    ];

    /**
     * Set by retype() for the one save that may change type and nullability.
     */
    private bool $retyping = false;

    /**
     * The scope of the for() builder that made this model, if any.
     */
    private ?string $restrictedScope = null;

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Setting $setting) {
            $setting->enforceIntegrity();
        });

        static::saved(function (Setting $setting) {
            $setting->forgetScopeCache();
        });

        static::deleted(function (Setting $setting) {
            $setting->forgetScopeCache();
        });
    }

    public function getTable()
    {
        $table = Config::get('pharaonic.settings.table');

        return is_string($table) && $table !== '' ? $table : 'settings';
    }

    public function getConnectionName()
    {
        $connection = $this->connection ?? Config::get('pharaonic.settings.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return SettingBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new SettingBuilder($query);
    }

    /**
     * The typed value.
     */
    public function getValueAttribute(): mixed
    {
        $raw = $this->attributes['value'] ?? null;

        return is_string($raw) ? ValueCodec::decode($raw, $this->settingKey(), $this->scope()) : null;
    }

    /**
     * Store the value as JSON; save() checks it against the type.
     */
    public function setValueAttribute(mixed $value): void
    {
        $this->attributes['value'] = ValueCodec::encode($value, $this->settingKey(), $this->scope());
    }

    /**
     * Change the type and nullability of this stored setting and save it,
     * converting the value with the safe casting rules. Nothing is saved when
     * the value cannot be converted or a definition declares other metadata.
     *
     * @throws SettingTypeMismatchException
     * @throws SettingDefinitionException
     */
    public function retype(string $type, ?bool $nullable = null): static
    {
        if (! $this->exists) {
            throw new LogicException('Only a stored setting can be retyped.');
        }

        self::assertType($type);

        $key = $this->settingKey();
        $identifier = $this->scope();
        $nullable = $nullable ?? (bool) $this->nullable;
        $value = $this->value;

        if ($value === null) {
            if (! $nullable) {
                throw SettingTypeMismatchException::null($key, $identifier);
            }
        } else {
            $value = TypeNormalizer::normalize($value, $type, CastingPolicy::SAFE, $key, $identifier);
        }

        $previous = $this->getAttributes();
        $this->type = $type;
        $this->nullable = $nullable;
        $this->value = $value;
        $this->retyping = true;

        try {
            $this->save();
        } catch (Throwable $exception) {
            $this->setRawAttributes($previous);

            throw $exception;
        } finally {
            $this->retyping = false;
        }

        return $this;
    }

    /**
     * Refuse to save this model in any scope but the given one.
     *
     * @internal Called by SettingBuilder for models it makes.
     */
    public function restrictToScope(string $identifier): void
    {
        $this->restrictedScope = $identifier;
    }

    /**
     * @internal
     */
    public static function otherScope(string $identifier): InvalidIdentifierException
    {
        return new InvalidIdentifierException(sprintf(
            'A setting made by the [%s] scope cannot be saved with another identifier.',
            $identifier
        ));
    }

    /**
     * Check and normalize the row before it is written.
     *
     * @throws SettingTypeMismatchException
     * @throws SettingDefinitionException
     */
    private function enforceIntegrity(): void
    {
        $identifier = $this->attributes['identifier'] ?? null;
        $identifier = Identifier::check(is_string($identifier) ? $identifier : self::GLOBAL);
        $key = Identifier::key($this->settingKey());

        if ($this->exists && ($this->isDirty('identifier') || $this->isDirty('key'))) {
            throw new LogicException("The identifier and key of the stored setting [{$key}] cannot change; create another setting instead.");
        }

        if ($this->restrictedScope !== null && $identifier !== $this->restrictedScope) {
            throw self::otherScope($this->restrictedScope);
        }

        $this->attributes['identifier'] = $identifier;

        $definition = Definition::find($key);
        $type = $this->attributes['type'] ?? null;
        $type = is_string($type) && $type !== '' ? $type : null;
        $nullable = array_key_exists('nullable', $this->attributes) && $this->attributes['nullable'] !== null
            ? (bool) $this->attributes['nullable']
            : null;
        $raw = $this->attributes['value'] ?? null;
        $value = is_string($raw) ? ValueCodec::decode($raw, $key, $identifier) : null;

        if ($type !== null) {
            self::assertType($type);
        }

        if ($this->exists && ! $this->retyping) {
            $storedType = $this->getOriginal('type');
            $storedNullable = (bool) $this->getOriginal('nullable');

            if (is_string($storedType) && $storedType !== '' && $type !== $storedType) {
                throw SettingTypeMismatchException::immutable($key, $identifier, 'type', $storedType, $type ?? 'null');
            }

            if ($nullable !== null && $nullable !== $storedNullable) {
                throw SettingTypeMismatchException::immutable($key, $identifier, 'nullability', self::flag($storedNullable), self::flag($nullable));
            }
        }

        if ($definition !== null) {
            if ($type !== null && $type !== $definition->type) {
                throw SettingDefinitionException::conflict($key, $identifier, 'type', $type, $definition->type);
            }

            if ($nullable !== null && $nullable !== $definition->nullable) {
                throw SettingDefinitionException::conflict($key, $identifier, 'nullability', self::flag($nullable), self::flag($definition->nullable));
            }

            $type = $definition->type;
            $nullable = $definition->nullable;
        }

        $nullable = $nullable ?? $value === null;

        if ($value === null) {
            if (! $nullable) {
                throw SettingTypeMismatchException::null($key, $identifier);
            }
        } else {
            $type = $type ?? SettingType::of($value);

            if ($type === null) {
                // Unreachable: the codec only stores supported values.
                throw new LogicException("The setting [{$key}] has a value of no supported type.");
            }

            $value = TypeNormalizer::normalize($value, $type, Definition::policy($definition), $key, $identifier);

            if ($definition !== null && $definition->rules !== [] && $definition->rules !== '') {
                // The type's own rule comes first, so size rules such as max:5
                // compare numbers as numbers and arrays by their count.
                $rules = is_string($definition->rules) ? explode('|', $definition->rules) : $definition->rules;
                array_unshift($rules, self::TYPE_RULES[$type]);

                Validator::make(['value' => $value], ['value' => $rules], [], ['value' => $key])->validate();
            }
        }

        $this->attributes['type'] = $type;
        $this->attributes['nullable'] = $nullable;
        $this->attributes['value'] = ValueCodec::encode($value, $key, $identifier);
    }

    /**
     * Forget the scope's cache, once the surrounding transaction commits.
     */
    private function forgetScopeCache(): void
    {
        $identifier = $this->attributes['identifier'] ?? null;

        if (is_string($identifier)) {
            (new ScopeCache)->invalidate($this->getConnection(), $this->getTable(), $identifier);
        }
    }

    private function settingKey(): string
    {
        $key = $this->attributes['key'] ?? '';

        return is_string($key) ? $key : '';
    }

    private function scope(): string
    {
        $identifier = $this->attributes['identifier'] ?? null;

        return is_string($identifier) ? $identifier : self::GLOBAL;
    }

    private static function assertType(string $type): void
    {
        if (! SettingType::isValid($type)) {
            throw new InvalidArgumentException(sprintf(
                'The setting type [%s] must be one of %s.',
                $type,
                implode(', ', SettingType::all())
            ));
        }
    }

    private static function flag(bool $value): string
    {
        return $value ? 'nullable' : 'not nullable';
    }
}
