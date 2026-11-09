## API Reference

### SettingBuilder

`Pharaonic\Laravel\Settings\Builders\SettingBuilder` extends `Illuminate\Database\Eloquent\Builder`, so every Eloquent builder method is available unchanged. These are its own methods. All but `for()` and `getIdentifier()` need a plain `for()` scope.

| Method | Returns | Description |
| --- | --- | --- |
| `for(Model\|string\|null $identifier = null)` | `static` | Scope to the global scope (`null` or `Setting::GLOBAL`), a named scope, or a saved model. Once per builder. |
| `fallbackTo(Model\|string\|null $identifier)` | `static` | Add a scope that reads fall back to. Duplicates are skipped. |
| `fallbackToGlobal()` | `static` | Add the global scope as the next fallback; the same as `fallbackTo(null)`. |
| `getIdentifier()` | `?string` | The `for()` identifier, or `null` before `for()`. |
| `getFallbackChain()` | `list<string>` | The identifiers reads try, in order. |
| `getValue(string $key, mixed $default = null)` | `mixed` | The value from the first scope storing the key; else `$default` (a closure is called), else the definition default, else `null`. |
| `getOwnValue(string $key, mixed $default = null)` | `mixed` | The same, for the `for()` scope only. |
| `hasValue(string $key)` | `bool` | Whether a scope of the chain stores the key. Defaults do not count. |
| `hasOwnValue(string $key)` | `bool` | Whether the `for()` scope stores the key. |
| `allValues()` | `array<string, mixed>` | Every stored value of the chain, the earliest scope winning, sorted by key. |
| `allOwnValues()` | `array<string, mixed>` | Every value of the `for()` scope, sorted by key. |
| `explainValue(string $key)` | `array` | `key`, `found`, `value`, `source`, `inherited`, `is_default`, `type`, `nullable`. |
| `setValue(string $key, mixed $value, ?string $type = null, ?bool $nullable = null)` | `Setting` | Store the value in the `for()` scope and return the saved row. |
| `setMany(array $values)` | `void` | Store `['key' => value, …]` in one transaction, all or nothing. |
| `forgetValue(string $key)` | `bool` | Delete the key from the `for()` scope; `true` when a row was deleted. |
| `retypeValue(string $key, string $type, ?bool $nullable = null)` | `Setting` | Change the stored type and nullability, converting the value with the safe rules. |

### Setting

`Pharaonic\Laravel\Settings\Models\Setting`

| Member | Description |
| --- | --- |
| `Setting::GLOBAL` | `'__global__'`, the identifier of the global scope. |
| `Setting::for($identifier)` | A scoped `SettingBuilder`. |
| `$setting->value` | The typed value. |
| `$setting->identifier`, `->key`, `->type`, `->nullable` | The stored metadata. |
| `$setting->retype(string $type, ?bool $nullable = null)` | Change type and nullability of the stored row and save it. Returns the model. |

### HasSettings

`Pharaonic\Laravel\Settings\Concerns\HasSettings`

| Method | Returns | Description |
| --- | --- | --- |
| `settings()` | `SettingBuilder` | `Setting::for($this)`. Not a relation. |
| `getSetting(string $key, mixed $default = null)` | `mixed` | `settings()->getValue()`. |
| `setSetting(string $key, mixed $value, ?string $type = null, ?bool $nullable = null)` | `Setting` | `settings()->setValue()`. |
| `hasSetting(string $key)` | `bool` | `settings()->hasValue()`. |
| `forgetSetting(string $key)` | `bool` | `settings()->forgetValue()`. |
| `allSettings()` | `array<string, mixed>` | `settings()->allValues()`. |

### Helper

| Function | Returns | Description |
| --- | --- | --- |
| `settings(Model\|string\|null $identifier = null)` | `SettingBuilder` | `Setting::for($identifier)`. Defined only when no `settings()` function exists yet. |

### Constants

| Class | Constants |
| --- | --- |
| `Pharaonic\Laravel\Settings\Enums\SettingType` | `STRING`, `INTEGER`, `FLOAT`, `BOOLEAN`, `ARRAY` |
| `Pharaonic\Laravel\Settings\Enums\CastingPolicy` | `STRICT`, `SAFE` |

### Exceptions

All in `Pharaonic\Laravel\Settings\Exceptions`. Messages name keys, scopes and types, never values.

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `InvalidIdentifierException` | `InvalidArgumentException` | The scope is an empty or too long string, or an unsaved or keyless model; or a model made by a scoped builder names another scope. |
| `InvalidSettingKeyException` | `InvalidArgumentException` | The key is empty, has an empty dot segment, is too long or invalid UTF-8; or `setMany()` gets an integer key. |
| `SettingTypeMismatchException` | `InvalidArgumentException` | A value does not match the type or nullability, or a write tries to change them. |
| `UnsupportedSettingValueException` | `InvalidArgumentException` | The value cannot be stored at all. |
| `SettingDefinitionException` | `LogicException` | A definition is invalid, or a stored row contradicts it. |
| `CorruptSettingValueException` | `UnexpectedValueException` | A stored value is not JSON the package wrote. |
| `UnscopedSettingQueryException` | `LogicException` | A value method runs without `for()` or with other constraints. |

Validation rules from definitions throw `Illuminate\Validation\ValidationException`.

### Artisan

| Command | Description |
| --- | --- |
| `settings:migrate-legacy` | Report on a 1.x table. Options: `--apply`, `--duplicates=fail\|latest`, `--ambiguous=fail\|string\|decode`, `--map=Type=prefix` (repeatable), `--backup=name`. |

### Publish Tags

| Tag | Publishes |
| --- | --- |
| `settings-config`, `pharaonic-config` | `config/pharaonic/settings.php` |
| `settings-migrations`, `pharaonic-migrations` | the migration into `database/migrations` |
| `laravel-settings`, `pharaonic` | both |
