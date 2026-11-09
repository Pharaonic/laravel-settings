<?php

return [
    /**
     * The database connection that stores the settings. Null uses the default
     * connection.
     */
    'connection' => env('SETTINGS_DB_CONNECTION'),

    /**
     * The table that stores the settings.
     */
    'table' => env('SETTINGS_DB_TABLE', 'settings'),

    /**
     * How a value that is not already of a setting's type is handled: `strict`
     * refuses it, `safe` converts the few unambiguous cases listed in the docs
     * (such as the string "20" for an integer setting) and refuses the rest.
     * A definition may set its own `casting`.
     */
    'types' => [
        'casting' => 'strict',
    ],

    /**
     * Each scope's rows are cached as one entry, forgotten after a write to
     * that scope commits. `store` null uses the default cache store, and `ttl`
     * (seconds) null keeps the entry until the next write.
     */
    'cache' => [
        'enabled' => env('SETTINGS_CACHE', true),
        'store' => env('SETTINGS_CACHE_STORE'),
        'ttl' => null,
    ],

    /**
     * Whether HasSettings deletes a model's settings when the model is deleted
     * permanently (a force delete, or a delete without SoftDeletes). A soft
     * delete never deletes settings.
     */
    'models' => [
        'purge_on_force_delete' => false,
    ],

    /**
     * Optional definitions for important keys, applied in every scope:
     *
     *     'checkout.minimum_order' => [
     *         'type' => 'float',         // string, integer, float, boolean, array
     *         'nullable' => false,
     *         'default' => 0.0,          // returned when no scope stores the key
     *         'casting' => 'safe',       // strict or safe
     *         'rules' => ['min:0'],      // Laravel validation rules
     *     ],
     *
     * Keep this file free of closures and objects so `config:cache` works.
     */
    'definitions' => [],
];
