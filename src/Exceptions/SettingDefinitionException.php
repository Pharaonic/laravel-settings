<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use LogicException;

/**
 * Thrown for an invalid entry in pharaonic.settings.definitions, or a stored
 * row whose type or nullability contradicts its key's definition.
 */
final class SettingDefinitionException extends LogicException
{
    public static function invalid(string $key, string $reason): self
    {
        return new self(sprintf('The settings definition [%s] is invalid: %s.', $key, $reason));
    }

    public static function conflict(string $key, string $identifier, string $attribute, string $stored, string $defined): self
    {
        return new self(sprintf(
            'The setting [%s] of the [%s] scope is stored with the %s [%s], but its definition declares [%s]; migrate the row with retypeValue().',
            $key,
            $identifier,
            $attribute,
            $stored,
            $defined
        ));
    }
}
