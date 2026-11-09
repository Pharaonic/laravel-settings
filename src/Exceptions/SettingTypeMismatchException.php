<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a value does not match a setting's type or nullability, or a
 * write tries to change them without retypeValue(). The message names the
 * key, the scope and the types, never the value itself.
 */
final class SettingTypeMismatchException extends InvalidArgumentException
{
    public static function value(string $key, string $identifier, string $type, string $given, string $policy): self
    {
        return new self(sprintf(
            'The setting [%s] of the [%s] scope is of type [%s] and cannot store a value of type [%s] under the [%s] casting policy.',
            $key,
            $identifier,
            $type,
            $given,
            $policy
        ));
    }

    public static function null(string $key, string $identifier): self
    {
        return new self(sprintf(
            'The setting [%s] of the [%s] scope is not nullable; change it with retypeValue() to store null.',
            $key,
            $identifier
        ));
    }

    public static function immutable(string $key, string $identifier, string $attribute, string $stored, string $given): self
    {
        return new self(sprintf(
            'The setting [%s] of the [%s] scope has the %s [%s]; a write cannot change it to [%s], use retypeValue() instead.',
            $key,
            $identifier,
            $attribute,
            $stored,
            $given
        ));
    }
}
