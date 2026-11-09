<?php

namespace Pharaonic\Laravel\Settings\Exceptions;

use LogicException;

/**
 * Thrown when a value method (getValue(), setValue(), …) is called on a
 * builder that is not a plain for() scope: without for(), or with extra
 * constraints such as where(), join(), limit() or select() that the value
 * methods would otherwise ignore.
 */
final class UnscopedSettingQueryException extends LogicException {}
