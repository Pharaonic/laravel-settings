## Upgrading

Version 1.x stored settings in `settings(name, value, settingable_type, settingable_id)`, with no type information and no unique index. The new schema is different, so the 1.x table is converted with an Artisan command, never by `migrate`.

### 1. Update the Code

| 1.x | Now |
| --- | --- |
| `use Pharaonic\Laravel\Settings\Traits\Settingable;` | `use Pharaonic\Laravel\Settings\Concerns\HasSettings;` |
| `Pharaonic\Laravel\Settings\Models\Settings` | `Pharaonic\Laravel\Settings\Models\Setting` |
| `settings()->name`, `settings()->get('name')` | `settings()->getValue('name')` |
| `settings()->set('name', $value); settings()->save();` | `settings()->setValue('name', $value);`, saved right away |
| `$model->settings->name` | `$model->getSetting('name')` |
| `$model->settings->set(...)` then `$model->save()` | `$model->setSetting(...)`, saved right away |
| `$model->clearSettings()` | `$model->settings()->get()->each->delete()` |

Settings are no longer deleted when a model is deleted, unless you set `models.purge_on_force_delete`, and never on a soft delete.

### 2. Check the Conversion

Back up your database first. Then run the command without options: it only reads and reports.

```bash title="Terminal" no-line-numbers
php artisan settings:migrate-legacy
```

It prints how many rows it read and would write, the rows per scope, and every row that blocks the conversion. It never prints values.

| Problem | What to do |
| --- | --- |
| The same key twice in one scope | `--duplicates=latest` keeps the most recently updated row (the later id on a tie). The default, `fail`, stops. |
| A value starting with `{` or `[` | 1.x read `{...}` back as JSON and `[...]` as text, so either may be JSON or text. `--ambiguous=string` keeps the text, `--ambiguous=decode` stores valid JSON as an array. The default, `fail`, stops. |
| A model type that is no class or morph alias any more | `--map="App\User=user"` maps a stored type to an identifier prefix. The option repeats. |
| Half-set model columns, invalid keys or UTF-8 | Fix the row in the table. |

Model rows become `{morph class}:{id}`, using your current morph map, so `settings($user)` finds them.

### 3. Type the Values

1.x stored everything as text: `true` as `"1"`, `false` as `""`, `20` as `"20"`. Without a definition, a converted row keeps the text 1.x returned, as a nullable `string`. Add [definitions](#definitions) for the keys you want typed before converting, and the command converts them with the safe casting rules:

```php title="config/pharaonic/settings.php"
'definitions' => [
    'max_items' => ['type' => 'integer'], // "20" becomes 20
    'enabled' => ['type' => 'boolean'],   // "1" becomes true
],
```

A value that cannot be converted safely blocks the conversion. You can also change types later with `retypeValue()`.

### 4. Convert

```bash title="Terminal" no-line-numbers
php artisan settings:migrate-legacy --apply
```

The command renames the 1.x table to `settings_legacy` (choose another name with `--backup=`), creates the new table, copies the converted rows with their timestamps, and checks the result against a checksum. If anything fails, it drops the new table and renames the backup back.

Running it again on a converted table changes nothing.

### Rolling Back

Drop the new `settings` table, rename `settings_legacy` back to `settings`, and reinstall 1.x. On MySQL, the rename and create are not transactional, so restore from your backup if the process was killed midway.
