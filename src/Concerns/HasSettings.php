<?php

namespace Pharaonic\Laravel\Settings\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Support\Identifier;
use Pharaonic\Laravel\Settings\Support\ScopeCache;

/**
 * Settings scoped to a saved model, stored under "{morph class}:{key}".
 *
 * settings() returns a scoped SettingBuilder, not a relation: with('settings')
 * and whereHas('settings') are not supported, and there is no $settings
 * attribute. Nothing is read when the model is loaded and nothing is written
 * when it is saved; every setting write is immediate. A soft delete keeps the
 * settings; a permanent delete removes them only when
 * pharaonic.settings.models.purge_on_force_delete is true.
 *
 * @mixin Model
 */
trait HasSettings
{
    public static function bootHasSettings(): void
    {
        static::deleted(function (Model $model) {
            if (! filter_var(Config::get('pharaonic.settings.models.purge_on_force_delete', false), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $identifier = Identifier::ofModel($model, false);
            $setting = new Setting;

            // One statement, so it joins the caller's transaction; the cache
            // is forgotten once that transaction commits.
            Setting::query()->toBase()->where('identifier', $identifier)->delete();

            (new ScopeCache)
                ->invalidate($setting->getConnection(), $setting->getTable(), $identifier);
        });
    }

    /**
     * The settings of this model, as a scoped SettingBuilder.
     */
    public function settings(): SettingBuilder
    {
        return Setting::for($this);
    }

    /**
     * The value of the key; see SettingBuilder::getValue().
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return func_num_args() > 1
            ? $this->settings()->getValue($key, $default)
            : $this->settings()->getValue($key);
    }

    /**
     * Store the value of the key right away; see SettingBuilder::setValue().
     */
    public function setSetting(string $key, mixed $value, SettingType|string|null $type = null, ?bool $nullable = null): Setting
    {
        return $this->settings()->setValue($key, $value, $type, $nullable);
    }

    public function hasSetting(string $key): bool
    {
        return $this->settings()->hasValue($key);
    }

    public function forgetSetting(string $key): bool
    {
        return $this->settings()->forgetValue($key);
    }

    /**
     * Every value stored for this model, keyed by key.
     *
     * @return array<string, mixed>
     */
    public function allSettings(): array
    {
        return $this->settings()->allValues();
    }
}
