<?php

namespace Pharaonic\Laravel\Settings\Enums;

/**
 * The types a setting can have, stored in the `type` column by value.
 */
enum SettingType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';

    /**
     * The type of a value about to be stored, or null for null.
     */
    public static function of(mixed $value): ?self
    {
        return match (true) {
            is_bool($value) => self::BOOLEAN,
            is_int($value) => self::INTEGER,
            is_float($value) => self::FLOAT,
            is_string($value) => self::STRING,
            is_array($value) => self::ARRAY,
            default => null,
        };
    }

    /**
     * The type named by a case or its value.
     *
     * @throws \InvalidArgumentException for an unknown name
     */
    public static function parse(self|string $type): self
    {
        if ($type instanceof self) {
            return $type;
        }

        return self::tryFrom($type) ?? throw new \InvalidArgumentException(sprintf(
            'The setting type [%s] must be one of %s.',
            $type,
            implode(', ', array_map(fn (self $case): string => $case->value, self::cases()))
        ));
    }

    /**
     * The Laravel validation rule of the type, added before a definition's
     * rules so size rules compare numbers as numbers.
     */
    public function rule(): string
    {
        return match ($this) {
            self::STRING => 'string',
            self::INTEGER => 'integer',
            self::FLOAT => 'numeric',
            self::BOOLEAN => 'boolean',
            self::ARRAY => 'array',
        };
    }
}
