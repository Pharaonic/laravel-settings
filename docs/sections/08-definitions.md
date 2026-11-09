## Definitions

Settings work without definitions. For important keys, a definition fixes the type, nullability, default, casting policy and validation rules in every scope: global, named and model.

```php title="config/pharaonic/settings.php"
'definitions' => [
    'checkout.minimum_order' => [
        'type' => 'float',
        'nullable' => false,
        'default' => 0.0,
        'casting' => 'safe',
        'rules' => ['min:0'],
    ],
    'notifications.email' => [
        'type' => 'boolean',
        'default' => true,
    ],
],
```

| Option | Required | Description |
| --- | --- | --- |
| `type` | yes | `string`, `integer`, `float`, `boolean` or `array`. |
| `nullable` | no | Default `false`. |
| `default` | no | Returned when no scope stores the key. Must be of `type` (or `null` when nullable). Never written to the table. |
| `casting` | no | `strict` or `safe`; overrides `types.casting` for this key. |
| `rules` | no | Laravel validation rules, as an array or a `|` string. |

Definition keys are the exact setting keys, so write them as literal array keys, not nested arrays.

### Defaults

```php
settings()->getValue('checkout.minimum_order');        // 0.0: the definition default
settings()->getValue('checkout.minimum_order', 5.0);   // 5.0: your default wins
settings()->hasValue('checkout.minimum_order');        // false: defaults are not stored
```

A default is used only when no scope of the [fallback chain](#fallback) stores the key. A stored `null` is a value, so it wins over every default.

### Validation

The value is type-checked (or safely cast) first, then validated with Laravel's validator. The type's own rule (`integer`, `numeric`, `boolean`, `string` or `array`) is added before yours, so size rules such as `max:5` compare numbers as numbers:

```php
settings()->setValue('checkout.minimum_order', -1.0);
// Illuminate\Validation\ValidationException: "The checkout.minimum_order must be at least 0."
```

Errors are reported under the `value` key, with the setting key as the attribute name. Rules are skipped for a stored `null`; nullability is already enforced by the type.

### When a Stored Row Contradicts Its Definition

A row stored before you added the definition may have another type or nullability. Reading or writing it throws `SettingDefinitionException` instead of returning a wrongly typed value. Fix the row with `retypeValue()`:

```php
settings()->retypeValue('checkout.minimum_order', 'float', false);
```

### Refused Definitions

A definition without a valid `type`, with an unknown option, a default of another type, or `encrypted` throws `SettingDefinitionException` when the key is used. Encrypted settings are not supported yet, so they are refused rather than stored as plaintext.
