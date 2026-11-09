<?php

namespace Pharaonic\Laravel\Settings\Enums;

/**
 * How a value that is not already of a setting's type is handled.
 */
final class CastingPolicy
{
    /**
     * Refuse every value that is not already of the setting's type.
     */
    public const STRICT = 'strict';

    /**
     * Convert the few unambiguous cases (such as "20" for an integer) and
     * refuse everything else.
     */
    public const SAFE = 'safe';

    private function __construct()
    {
    }

    public static function isValid(string $policy): bool
    {
        return $policy === self::STRICT || $policy === self::SAFE;
    }
}
