<?php

namespace Pharaonic\Laravel\Settings\Support;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Settings\Exceptions\InvalidIdentifierException;
use Pharaonic\Laravel\Settings\Exceptions\InvalidSettingKeyException;
use Pharaonic\Laravel\Settings\Models\Setting;

/**
 * Turns a scope into the stored identifier and checks keys.
 *
 * @internal
 */
final class Identifier
{
    /**
     * The length of the identifier and key columns, in characters.
     */
    public const MAX_LENGTH = 191;

    /**
     * The identifier of a scope: Setting::GLOBAL for null, the string itself,
     * or "{morph class}:{key}" for a saved model.
     *
     * @throws InvalidIdentifierException
     */
    public static function resolve(Model|string|null $identifier): string
    {
        if ($identifier === null) {
            return Setting::GLOBAL;
        }

        if ($identifier instanceof Model) {
            return self::ofModel($identifier, true);
        }

        return self::check($identifier);
    }

    /**
     * The identifier of a model; $saved false skips the "exists" check for a
     * model that was just deleted.
     *
     * @throws InvalidIdentifierException
     */
    public static function ofModel(Model $model, bool $saved): string
    {
        $key = $model->getKey();

        if (($saved && ! $model->exists) || (! is_int($key) && (! is_string($key) || $key === ''))) {
            throw new InvalidIdentifierException(sprintf(
                'The settings model [%s] must be saved with a string or integer key.',
                get_class($model)
            ));
        }

        return self::check($model->getMorphClass().':'.$key);
    }

    /**
     * @throws InvalidIdentifierException
     */
    public static function check(string $identifier): string
    {
        if ($identifier === '' || preg_match('//u', $identifier) !== 1 || mb_strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidIdentifierException(sprintf(
                'The settings identifier must be null, a saved model, or a UTF-8 string of 1 to %d characters.',
                self::MAX_LENGTH
            ));
        }

        return $identifier;
    }

    /**
     * @throws InvalidSettingKeyException
     */
    public static function key(string $key): string
    {
        if (preg_match('//u', $key) !== 1) {
            throw new InvalidSettingKeyException('The setting key must be valid UTF-8.');
        }

        if ($key === '' || in_array('', explode('.', $key), true) || mb_strlen($key) > self::MAX_LENGTH) {
            throw new InvalidSettingKeyException(sprintf(
                'The setting key [%s] must be 1 to %d characters without empty dot segments.',
                $key,
                self::MAX_LENGTH
            ));
        }

        return $key;
    }
}
