<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use InvalidArgumentException;

/**
 * Thrown for a key that is empty, has an empty dot segment, is longer than
 * the column, or is not valid UTF-8.
 */
final class InvalidSettingKeyException extends InvalidArgumentException {}
