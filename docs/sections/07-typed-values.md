## Typed Values

Every row stores a `type` and a `nullable` flag next to its value.

| Type | PHP value | Enum case |
| --- | --- | --- |
| `string` | `string` | `SettingType::STRING` |
| `integer` | `int` | `SettingType::INTEGER` |
| `float` | finite `float` | `SettingType::FLOAT` |
| `boolean` | `bool` | `SettingType::BOOLEAN` |
| `array` | `array` of the above, nested up to 63 levels | `SettingType::ARRAY` |

`Pharaonic\Laravel\Settings\Enums\SettingType` is a string-backed enum. Every method that takes a type accepts the case or its value (`SettingType::INTEGER` or `'integer'`), and `$setting->type` returns the case:

```php
$setting = settings()->setValue('count', 20);

$setting->type;                            // SettingType::INTEGER
$setting->type === SettingType::INTEGER;   // true
$setting->getAttributes()['type'];         // "integer", the stored value
```

An untyped first `null` has `null` as its type.

### How a New Setting Gets Its Type

In this order: the `$type` argument, then the key's [definition](#definitions), then the first value.

```php
use Pharaonic\Laravel\Settings\Enums\SettingType;

settings()->setValue('enabled', true);       // boolean
settings()->setValue('count', 20);           // integer
settings()->setValue('price', 19.99);        // float
settings()->setValue('title', 'Pharaonic');  // string
settings()->setValue('locales', ['ar']);     // array
settings()->setValue('limit', null, SettingType::INTEGER); // integer, nullable
```

### Null

A first `null` without a type is stored untyped and nullable. The first non-null value then fixes the type, and the setting stays nullable:

```php
settings()->setValue('unknown', null);  // type null, nullable
settings()->setValue('unknown', 5);     // type integer, still nullable
settings()->setValue('unknown', 'x');   // SettingTypeMismatchException
settings()->setValue('unknown', null);  // allowed
```

A setting created with a non-null value is not nullable. Pass `nullable: true` (the fourth argument) to allow `null` later; `nullable: false` with a `null` value is refused.

### The Type Never Changes on a Write

```php
settings()->setValue('count', 20);
settings()->setValue('count', 21);    // fine
settings()->setValue('count', '21');  // SettingTypeMismatchException (strict policy)
settings()->setValue('count', 21.0);  // SettingTypeMismatchException
settings()->setValue('count', 21, SettingType::FLOAT); // SettingTypeMismatchException
```

Change the type deliberately with `retypeValue()`. It locks the row, converts the stored value with the safe rules below, and saves type, nullability and value together, or nothing:

```php
settings()->setValue('limit', '20');                     // string
settings()->retypeValue('limit', SettingType::INTEGER);  // integer 20
settings()->retypeValue('limit', SettingType::STRING, true); // "20", nullable
settings()->retypeValue('label', SettingType::INTEGER);  // "twenty": SettingTypeMismatchException
```

### Casting Policies

The `strict` policy, the default, accepts only values already of the setting's type. The `safe` policy also converts these cases, and refuses everything else:

| Type | Safe conversions | Still refused |
| --- | --- | --- |
| `integer` | canonical decimal strings in range: `"20"`, `"-7"`, `"0"` | `"20.5"`, `"020"`, `" 20"`, `"+20"`, `"-0"`, `"1e3"`, overflow, `20.0`, `true` |
| `float` | integers up to 2^53; decimal strings of at most 15 significant digits: `"19.99"`, `"20"` | exponents, longer decimals, `"abc"` |
| `boolean` | `"true"`, `"false"`, `"1"`, `"0"`, `1`, `0` | `"TRUE"`, `"yes"`, `""`, `2` |
| `string` | integers (`42` becomes `"42"`), finite floats (`1.5` becomes `"1.5"`, `2.0` becomes `"2.0"`) | booleans, arrays |
| `array` | none | anything but an array |

Enable it for every key, or per key in a definition:

```php title="config/pharaonic/settings.php"
'types' => [
    'casting' => 'safe',
],
```

:::warning PHP's loose casts are never used
`(bool) "false"` is `true` in PHP and `(int) "20.5"` is `20`, so neither is a conversion rule here.
:::

### Values That Cannot Be Stored

Objects, closures, resources, `INF`, `NAN`, invalid UTF-8 (in strings or array keys), and arrays nested deeper than 63 levels throw `UnsupportedSettingValueException`. Its message names the key, the scope and the type, never the value.

### Storage

Values are stored as JSON. Strings that look like JSON stay strings, and JSON objects come back as associative arrays, never `stdClass`:

```php
settings()->setValue('raw', '{"a":1}');
settings()->getValue('raw');   // '{"a":1}', a string

settings()->setValue('map', ['a' => 1]);
settings()->getValue('map');   // ['a' => 1]
```
