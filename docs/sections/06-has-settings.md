## HasSettings

Add `HasSettings` to any Eloquent model whose records have their own settings.

```php title="app/Models/Company.php"
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Settings\Concerns\HasSettings;

class Company extends Model
{
    use HasSettings;
}
```

```php
$company->setSetting('theme', 'dark');      // Setting row, saved right away
$company->getSetting('theme', 'light');     // "dark"
$company->hasSetting('theme');              // true
$company->allSettings();                    // ['theme' => 'dark']
$company->forgetSetting('theme');           // true

$company->settings()->get();                // Eloquent collection of Setting models
$company->settings()->getValue('theme');    // the same as getSetting()
```

`settings()` returns `Setting::for($this)`, so every [value method](#api-reference) works on it, `fallbackTo()` included.

### What the Trait Never Does

- **It is not a relation.** `with('settings')`, `whereHas('settings')`, and `$company->settings` (without parentheses) throw; use `$company->settings()`.
- **It never queries on its own.** Loading or saving the model runs no settings query.
- **It never waits for `save()`.** `setSetting()` writes immediately, even when the model has unsaved changes.

### Deleting Models

| Delete | Settings |
| --- | --- |
| Soft delete (`delete()` with `SoftDeletes`) | Kept |
| Restore | Still there |
| Permanent delete (`forceDelete()`, or `delete()` without `SoftDeletes`) | Kept, unless `models.purge_on_force_delete` is `true` |

With purging on, the settings are deleted inside the transaction that deletes the model, so a rollback keeps both.

:::warning Mass deletes
`Company::query()->where(...)->delete()` fires no model events, so purging cannot see it. Delete the settings of those models before the mass delete, such as `$company->settings()->get()->each->delete()`, so the cache is forgotten as well.
:::
