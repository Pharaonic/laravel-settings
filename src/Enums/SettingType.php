<?php

namespace Pharaonic\Laravel\Settings\Enums;

/**
 * The types a setting can have. A constants class rather than a native enum,
 * because the package runs on PHP 8.0; pass the constants or their strings.
 */
final class SettingType
{
    public const STRING = 'string';

    public const INTEGER = 'integer';

    public const FLOAT = 'float';

    public const BOOLEAN = 'boolean';

    public const ARRAY = 'array';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::STRING, self::INTEGER, self::FLOAT, self::BOOLEAN, self::ARRAY];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * The type of a value about to be stored, or null for null.
     */
    public static function of(mixed $value): ?string
    {
        if (is_bool($value)) {
            return self::BOOLEAN;
        }

        if (is_int($value)) {
            return self::INTEGER;
        }

        if (is_float($value)) {
            return self::FLOAT;
        }

        if (is_string($value)) {
            return self::STRING;
        }

        if (is_array($value)) {
            return self::ARRAY;
        }

        return null;
    }
}
