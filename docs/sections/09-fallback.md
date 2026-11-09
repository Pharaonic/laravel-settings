## Fallback

A read only sees its own scope until you add fallback scopes with `fallbackTo()`. They are tried in the order you add them.

```php
$theme = settings($branch)
    ->fallbackTo($company)
    ->fallbackTo(null)          // the global scope
    ->getValue('theme', 'light');
```

The order is branch, then company, then global, then your default (or the definition's default). The package never finds a parent on its own: there is no relation lookup and no automatic global fallback.

`fallbackToGlobal()` is the same as `fallbackTo(null)` and `fallbackTo(Setting::GLOBAL)`, and reads better at the end of a chain:

```php
$theme = settings($branch)
    ->fallbackTo($company)
    ->fallbackToGlobal()
    ->getValue('theme', 'light');
```

### Missing Versus Null

A missing key moves on to the next scope. A stored `null` is present, so it stops the chain:

```php
settings()->setValue('theme', 'blue');
settings($company)->setValue('theme', null);

settings($branch)->fallbackTo($company)->fallbackTo(null)->getValue('theme', 'light'); // null
```

### Own Values

The `Own` methods ignore the fallbacks and read the `for()` scope only:

```php
$chain = settings($branch)->fallbackTo($company);

$chain->getOwnValue('theme');   // the branch's value, or the default
$chain->hasOwnValue('theme');
$chain->allOwnValues();
```

### All Values

`allValues()` overlays the chain: for each exact key the earliest scope wins.

```php
settings()->setMany(['a' => 'global', 'b' => 'global']);
settings($company)->setValue('b', 'company');

settings($company)->fallbackToGlobal()->allValues(); // ['a' => 'global', 'b' => 'company']
```

### Explaining a Value

`explainValue()` tells you where a value comes from:

```php
settings($branch)->fallbackTo($company)->fallbackTo(null)->explainValue('branding.primary_color');
// [
//     'key' => 'branding.primary_color',
//     'found' => true,
//     'value' => '#0EA5E9',
//     'source' => 'company:12',
//     'inherited' => true,     // found in a fallback scope
//     'is_default' => false,
//     'type' => 'string',
//     'nullable' => false,
// ]
```

When no scope stores the key, `found` is `false`, `source` is `null`, and `value`, `type` and `nullable` come from the definition (`is_default` is `true` when it has a default), or are `null`.

### Rules of the Chain

- A scope already in the chain is skipped, so the order is first-seen.
- The chain belongs to its builder: `settings($branch)` called again starts without fallbacks.
- Writes (`setValue()`, `forgetValue()`, …) only ever touch the `for()` scope.
- `getFallbackChain()` returns the identifiers in order.
