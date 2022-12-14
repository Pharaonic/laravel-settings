<?php

namespace Pharaonic\Laravel\Settings\Traits;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Settings\Classes\Settings as ClassesSettings;
use Pharaonic\Laravel\Settings\Models\Settings;

/**
 * Settingable any model.
 *
 * @property \Illuminate\Support\Collection|null $settings
 *
 * @author Moamen Eltouny (Raggi) <raggi@raggitech.com>
 */
trait Settingable
{
    /**
     * Settings records list.
     *
     * @var \Pharaonic\Laravel\Settings\Classes\Settings
     */
    public $settings_data;

    /**
     * Boot Settingable (saved)
     *
     * @return void
     */
    public static function bootSettingable()
    {
        // STORE/UPDATE
        static::saved(function (Model $model) {
            $model->settings->save();
        });

        // DESTROY
        static::deleting(function (Model $model) {
            $model->clearSettings();
        });
    }

    /**
     * Get the user settings.
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    private function settingables()
    {
        return $this->morphMany(Settings::class, 'settingable');
    }

    /**
     * Getting Settings Object
     *
     * @return ClassesSettings
     */
    public function getSettingsAttribute()
    {
        if ($this->settings_data) return $this->settings_data;

        return $this->settings_data = new ClassesSettings($this, $this->settingables()->get()->keyBy('name'));
    }

    /**
     * Clear all settings
     *
     * @return boolean
     */
    public function clearSettings()
    {
        return $this->settingables()->delete() > 0;
    }
}
