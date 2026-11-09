<?php

namespace Pharaonic\Laravel\Settings\Support;

use Illuminate\Support\Facades\Config;
use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\SettingDefinitionException;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;

/**
 * One entry of pharaonic.settings.definitions: the type, nullability,
 * default, casting policy and validation rules of a key in every scope.
 *
 * @internal
 */
final class Definition
{
    /**
     * @param  array<array-key, mixed>|string  $rules
     */
    private function __construct(
        public string $key,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public ?string $casting,
        public array|string $rules,
    ) {
    }

    /**
     * The definition of the key, or null when it has none.
     *
     * @throws SettingDefinitionException
     */
    public static function find(string $key): ?self
    {
        $definitions = Config::get('pharaonic.settings.definitions');

        if (! is_array($definitions) || ! array_key_exists($key, $definitions)) {
            return null;
        }

        $definition = $definitions[$key];

        if (! is_array($definition)) {
            throw SettingDefinitionException::invalid($key, 'it must be an array');
        }

        $type = $definition['type'] ?? null;

        if (! is_string($type) || ! SettingType::isValid($type)) {
            throw SettingDefinitionException::invalid($key, 'type must be one of '.implode(', ', SettingType::all()));
        }

        $nullable = $definition['nullable'] ?? false;

        if (! is_bool($nullable)) {
            throw SettingDefinitionException::invalid($key, 'nullable must be a boolean');
        }

        $casting = $definition['casting'] ?? null;

        if ($casting !== null && (! is_string($casting) || ! CastingPolicy::isValid($casting))) {
            throw SettingDefinitionException::invalid($key, 'casting must be strict or safe');
        }

        $rules = $definition['rules'] ?? [];

        if (! is_array($rules) && ! is_string($rules)) {
            throw SettingDefinitionException::invalid($key, 'rules must be a string or an array of rules');
        }

        // Fail closed: storing the value as plaintext would break the promise.
        if (($definition['encrypted'] ?? false) !== false) {
            throw SettingDefinitionException::invalid($key, 'encrypted settings are not supported by this release');
        }

        $unknown = array_diff(array_keys($definition), ['type', 'nullable', 'default', 'casting', 'rules', 'encrypted']);

        if ($unknown !== []) {
            throw SettingDefinitionException::invalid($key, 'unknown option ['.implode(', ', $unknown).']');
        }

        $hasDefault = array_key_exists('default', $definition);
        $default = $definition['default'] ?? null;

        if ($hasDefault) {
            if ($default === null && ! $nullable) {
                throw SettingDefinitionException::invalid($key, 'the default is null but the setting is not nullable');
            }

            if ($default !== null) {
                try {
                    $default = TypeNormalizer::normalize($default, $type, CastingPolicy::STRICT, $key, 'definition');
                } catch (SettingTypeMismatchException) {
                    throw SettingDefinitionException::invalid($key, "the default is not of type {$type}");
                }
            }
        }

        return new self($key, $type, $nullable, $hasDefault, $default, $casting, $rules);
    }

    /**
     * The casting policy of a key: its definition's, then the configured one.
     */
    public static function policy(?self $definition): string
    {
        if ($definition !== null && $definition->casting !== null) {
            return $definition->casting;
        }

        $policy = Config::get('pharaonic.settings.types.casting', CastingPolicy::STRICT);

        if (! is_string($policy) || ! CastingPolicy::isValid($policy)) {
            throw new SettingDefinitionException('The pharaonic.settings.types.casting option must be strict or safe.');
        }

        return $policy;
    }
}
