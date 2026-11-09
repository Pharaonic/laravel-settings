## Scopes

Every setting belongs to one scope, stored in the `identifier` column.

| Scope | Call | Stored identifier |
| --- | --- | --- |
| Global | `settings()`, `settings(null)`, `Setting::for()` | `__global__` |
| Named | `settings('checkout')` | `checkout` |
| Model | `settings($company)` | `{morph class}:{key}`, such as `App\Company:12` |

Scopes never share rows: the same key can hold a different value in each one, and a read only sees its own scope unless you add [fallbacks](#fallback).

### Model Scopes

A model scope uses the model's morph class and key, so it honors your morph map:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::morphMap(['company' => \App\Company::class]);
```

```php
settings($company)->setValue('timezone', 'Africa/Cairo');

settings($company)->getIdentifier();     // "company:12"
settings('company:12')->getValue('timezone'); // "Africa/Cairo": the same scope
```

Integer, string and UUID keys all work, including `0`. An unsaved model, or one without a string or integer key, throws `InvalidIdentifierException`.

:::warning Changing the morph map
Adding or changing a morph map alias changes the identifier of that model's scope, so its stored settings are no longer found. Keep aliases stable, or rename the stored identifiers in the same deploy.
:::

### Identifiers

An identifier is 1 to 191 UTF-8 characters. Identifiers are compared exactly, including case: `Checkout` and `checkout` are two scopes, on MySQL and MariaDB too (the columns use a binary collation).

:::danger An identifier is not a permission
`settings('company:12')` reads company 12's settings for anyone who knows the string. Never build an identifier from request input without checking that the user may access that scope.
:::
