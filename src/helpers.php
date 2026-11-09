<?php

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Settings\Builders\SettingBuilder;
use Pharaonic\Laravel\Settings\Models\Setting;

if (! function_exists('settings')) {
    /**
     * The settings of a scope: Setting::for($identifier). Defined only when
     * no other package or the application already defines settings().
     */
    function settings(Model|string|null $identifier = null): SettingBuilder
    {
        return Setting::for($identifier);
    }
}
