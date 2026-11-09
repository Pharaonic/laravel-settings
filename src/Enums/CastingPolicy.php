<?php

namespace Pharaonic\Laravel\Settings\Enums;

/**
 * How a value that is not already of a setting's type is handled.
 */
enum CastingPolicy: string
{
    /**
     * Refuse every value that is not already of the setting's type.
     */
    case STRICT = 'strict';

    /**
     * Convert the few unambiguous cases (such as "20" for an integer) and
     * refuse everything else.
     */
    case SAFE = 'safe';
}
