<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a value cannot be stored. The message names the key, the scope
 * and the type, never the value itself.
 */
final class UnsupportedSettingValueException extends InvalidArgumentException
{
    public function __construct(string $key, string $identifier, string $type)
    {
        parent::__construct(sprintf(
            'The setting [%s] of the [%s] scope cannot store a value of type [%s]; only null, bool, int, finite float, UTF-8 string and arrays of them are supported.',
            $key,
            $identifier,
            $type
        ));
    }
}
