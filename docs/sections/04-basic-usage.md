## Basic Usage

`settings($scope)` returns a query on the `Setting` model scoped to one identifier, the same as `Setting::for($scope)`. Its value methods read and write typed values.

```php
settings()->setValue('site.name', 'Pharaonic');
settings()->getValue('site.name');                  // "Pharaonic"
settings()->getValue('site.tagline', 'Welcome');    // "Welcome": not stored
settings()->hasValue('site.name');                  // true
settings()->forgetValue('site.name');               // true: a row was deleted
```

Writes go to the database right away and return the saved `Setting` row:

```php
$setting = settings('checkout')->setValue('minimum', 100);

$setting->type;     // "integer"
$setting->value;    // 100
$setting->nullable; // false
```

### Several Values at Once

`setMany()` stores every value in one transaction, or none of them:

```php
settings()->setMany([
    'site.name' => 'Pharaonic',
    'site.locales' => ['ar', 'en'],
    'site.maintenance' => false,
]);
```

It takes an array keyed by setting keys. A list such as `['a', 'b']`, or any integer key, is refused with `InvalidSettingKeyException`; use `setValue()` for a numeric key.

### All Values

```php
settings()->allValues();
// ['site.locales' => ['ar', 'en'], 'site.maintenance' => false, 'site.name' => 'Pharaonic']
```

The array is flat and sorted by key. Dotted keys are exact strings: `mail.from` and `mail.from.address` are two separate settings, never merged into nested arrays.

### Lazy Defaults

A closure default is only called when no scope stores the key. A stored `null` is a value, so the default is not used:

```php
settings()->getValue('report.limit', function () {
    return expensiveDefault();
});
```

### Keys

A key is 1 to 191 UTF-8 characters, and may use dots, but not empty segments: `.x`, `x.`, and `x..y` throw `InvalidSettingKeyException`.
