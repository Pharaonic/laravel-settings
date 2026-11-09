<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use UnexpectedValueException;

/**
 * Thrown when a stored value is not the JSON the package writes, for example
 * after a direct database edit. The message never contains the stored text.
 */
final class CorruptSettingValueException extends UnexpectedValueException
{
    public function __construct(string $key, string $identifier)
    {
        parent::__construct(sprintf(
            'The stored value of the setting [%s] of the [%s] scope is not valid JSON written by pharaonic/laravel-settings.',
            $key,
            $identifier
        ));
    }
}
