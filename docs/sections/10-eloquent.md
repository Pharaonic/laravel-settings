## Eloquent Model

`Pharaonic\Laravel\Settings\Models\Setting` is a regular Eloquent model, and `Setting::query()` returns its `SettingBuilder`. Nothing Eloquent offers is changed: `get()`, `first()` and the rest return `Setting` models.

```php
use Pharaonic\Laravel\Settings\Models\Setting;

Setting::for()->get();                                  // the global rows
Setting::for('checkout')->where('type', 'integer')->get();
Setting::for($company)->orderBy('key')->pluck('key');
Setting::query()->where('identifier', Setting::GLOBAL)->count();
```

| Column | Description |
| --- | --- |
| `identifier` | The scope, never `NULL`; `__global__` for the global scope. |
| `key` | The exact key. Unique with `identifier`. |
| `type` | `string`, `integer`, `float`, `boolean`, `array`, or `NULL` for an untyped first `null`. |
| `nullable` | Whether the setting accepts `null`. |
| `value` | The JSON of the value. |

`$setting->value` returns the typed value; `$setting->getAttributes()['value']` is the stored JSON.

### Value Methods Need a Plain Scope

The value methods only run on a plain `for()` scope, optionally with `fallbackTo()` and `orderBy()`. Anything else throws `UnscopedSettingQueryException`, because the value methods would otherwise ignore it:

```php
Setting::query()->getValue('x');                       // no for(): refused
Setting::for()->where('type', 'string')->getValue('x'); // extra where(): refused
Setting::for()->where('type', 'string')->get();        // fine: plain Eloquent
```

The same goes for `limit()`, `select()`, joins, `distinct()`, eager loading, global scopes, and a query on another connection (`Setting::on(...)`).

### Saving the Model Directly

A native save follows the same rules as `setValue()`: the value is checked (or safely cast) against the type, definitions and rules apply, and the scope's cache is forgotten after the commit.

```php
$setting = Setting::for()->where('key', 'site.count')->firstOrFail();
$setting->value = 4;
$setting->save();

Setting::create(['identifier' => 'checkout', 'key' => 'minimum', 'value' => 100]);
```

The type and nullability of a stored row can only change through `$setting->retype($type, $nullable)` (what `retypeValue()` calls), and its identifier and key cannot change at all.

:::warning Query-builder writes bypass the package
`Setting::query()->update([...])`, `DB::table('settings')`, and raw SQL skip the type checks, definitions and cache invalidation. Scopes cached before such a write keep their old rows until the next package write to the scope, or until `cache.ttl` expires.
:::

:::info Concurrency
Writes run in a transaction under a row lock. A new key is inserted inside a savepoint; when another request inserts the same key first, the unique `(identifier, key)` index refuses the second row, and the write validates against the row that won. So two requests can never leave two rows, or flip a type. A plain `$setting->save()` validates against the state it loaded, without a lock; use `setValue()` when requests may race.
:::
