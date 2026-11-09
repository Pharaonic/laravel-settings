<?php

namespace Pharaonic\Laravel\Settings\Support;

use JsonException;
use Pharaonic\Laravel\Settings\Exceptions\CorruptSettingValueException;
use Pharaonic\Laravel\Settings\Exceptions\UnsupportedSettingValueException;

/**
 * Stores values as JSON: null, bool, int, finite float, UTF-8 string, and
 * arrays of them. JSON objects come back as associative arrays, never as
 * stdClass, and a stored string is never guessed to be JSON.
 *
 * @internal
 */
final class ValueCodec
{
    private const DEPTH = 64;

    /**
     * @throws UnsupportedSettingValueException
     */
    public static function encode(mixed $value, string $key, string $identifier): string
    {
        self::ensureSupported($value, $key, $identifier, 1);

        try {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, self::DEPTH);
        } catch (JsonException) {
            throw new UnsupportedSettingValueException($key, $identifier, 'unencodable value');
        }
    }

    /**
     * @throws CorruptSettingValueException
     */
    public static function decode(string $raw, string $key, string $identifier): mixed
    {
        try {
            return json_decode($raw, true, self::DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CorruptSettingValueException($key, $identifier);
        }
    }

    private static function ensureSupported(mixed $value, string $key, string $identifier, int $depth): void
    {
        if (is_array($value)) {
            if ($depth >= self::DEPTH) {
                throw new UnsupportedSettingValueException($key, $identifier, 'array nested deeper than '.self::DEPTH.' levels');
            }

            foreach ($value as $nested => $item) {
                if (is_string($nested) && preg_match('//u', $nested) !== 1) {
                    throw new UnsupportedSettingValueException($key, $identifier, 'non-UTF-8 array key');
                }

                self::ensureSupported($item, $key, $identifier, $depth + 1);
            }

            return;
        }

        if (is_float($value) && ! is_finite($value)) {
            $type = 'non-finite float';
        } elseif (is_string($value) && preg_match('//u', $value) !== 1) {
            $type = 'non-UTF-8 string';
        } elseif ($value === null || is_scalar($value)) {
            return;
        } else {
            $type = get_debug_type($value);
        }

        throw new UnsupportedSettingValueException($key, $identifier, $type);
    }
}
