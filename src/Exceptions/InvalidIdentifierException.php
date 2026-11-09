<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use InvalidArgumentException;

/**
 * Thrown for a scope that cannot address settings: an empty or too long
 * string, or a model that is not saved or has no string or integer key.
 */
final class InvalidIdentifierException extends InvalidArgumentException {}
