<?php

namespace Pharaonic\Laravel\Settings\Support;

use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;

/**
 * Checks a non-null value against a setting type and, under the safe policy,
 * converts the unambiguous cases. Never uses PHP's loose casts: (bool) "false"
 * is true and (int) "20.5" is 20, so neither is a conversion rule here.
 *
 * @internal
 */
final class TypeNormalizer
{
    /**
     * The largest integer a float holds exactly (2^53).
     */
    private const FLOAT_EXACT_INT = 9007199254740992;

    /**
     * @throws SettingTypeMismatchException
     */
    public static function normalize(mixed $value, SettingType $type, CastingPolicy $policy, string $key, string $identifier): mixed
    {
        if (SettingType::of($value) === $type && ! ($type === SettingType::FLOAT && is_float($value) && ! is_finite($value))) {
            return $value;
        }

        if ($policy === CastingPolicy::SAFE) {
            $converted = self::convert($value, $type);

            if ($converted !== null) {
                return $converted[0];
            }
        }

        throw SettingTypeMismatchException::value($key, $identifier, $type->value, get_debug_type($value), $policy->value);
    }

    /**
     * The safe conversion of the value, wrapped in an array, or null when
     * there is none.
     *
     * @return array{0: mixed}|null
     */
    private static function convert(mixed $value, SettingType $type): ?array
    {
        switch ($type) {
            case SettingType::INTEGER:
                // Canonical decimal only: no sign on zero, padding, exponent or fraction.
                if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1 && (string) (int) $value === $value) {
                    return [(int) $value];
                }

                return null;
            case SettingType::FLOAT:
                if (is_int($value) && abs($value) <= self::FLOAT_EXACT_INT) {
                    return [(float) $value];
                }

                // At most 15 significant digits, which a float always round-trips.
                if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value) === 1
                    && strlen(ltrim(str_replace(['-', '.'], '', $value), '0')) <= 15) {
                    return [(float) $value];
                }

                return null;
            case SettingType::BOOLEAN:
                if ($value === 'true' || $value === '1' || $value === 1) {
                    return [true];
                }

                if ($value === 'false' || $value === '0' || $value === 0) {
                    return [false];
                }

                return null;
            case SettingType::STRING:
                if (is_int($value)) {
                    return [(string) $value];
                }

                if (is_float($value) && is_finite($value)) {
                    // The shortest text that reads back as the same float.
                    return [json_encode($value, JSON_PRESERVE_ZERO_FRACTION)];
                }

                return null;
            default:
                return null;
        }
    }
}
