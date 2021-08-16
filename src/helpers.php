<?php

use Pharaonic\Laravel\Settings\Classes\Settings;

/**
 * Getting Settings Object
 *
 * @return Pharaonic\Laravel\Settings\Classes\Settings
 */
function settings()
{
    if (!app()->has('Settings'))
        app()->instance('Settings', new Settings);

    return app('Settings');
}
