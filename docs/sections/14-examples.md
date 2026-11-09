## Examples

### 1. Branch, Company and Global Branding

Each branch may override its company, and each company the application:

```php
$color = settings($branch)
    ->fallbackTo($branch->company)
    ->fallbackToGlobal()
    ->getValue('branding.primary_color', '#0EA5E9');
```

### 2. User Preferences with Defaults

- ===Definition

  ```php title="config/pharaonic/settings.php"
  'definitions' => [
      'ui.language' => ['type' => 'string', 'default' => 'en', 'rules' => ['in:ar,en']],
      'notifications.email' => ['type' => 'boolean', 'default' => true],
  ],
  ```

- ===Model

  ```php title="app/Models/User.php"
  use Pharaonic\Laravel\Settings\Concerns\HasSettings;

  class User extends Authenticatable
  {
      use HasSettings;
  }
  ```

- ===Controller

  ```php title="app/Http/Controllers/PreferencesController.php"
  public function update(Request $request)
  {
      $request->user()->settings()->setMany([
          'ui.language' => $request->input('language'),
          'notifications.email' => $request->boolean('email'),
      ]);

      return back();
  }
  ```

`$user->getSetting('ui.language')` is `"en"` until the user picks a language, and `setMany()` refuses `"fr"` with a validation error, storing neither value.

### 3. Checkout Rules from a Form

A form sends strings. The safe policy turns `"100"` into `100.0` for a float setting, and still refuses `"abc"`:

```php title="config/pharaonic/settings.php"
'definitions' => [
    'checkout.minimum_order' => ['type' => 'float', 'casting' => 'safe', 'rules' => ['min:0']],
],
```

```php
settings('checkout')->setValue('checkout.minimum_order', $request->input('minimum')); // "100" becomes 100.0
```

### 4. Settings with Other Changes in One Transaction

```php
DB::transaction(function () use ($company, $plan) {
    $company->update(['plan' => $plan->name]);
    $company->setSetting('limits.users', $plan->users);
});
```

If the update fails, the setting is rolled back with it, and other requests never see the new limit before the commit.

### 5. Debugging Where a Value Comes From

```php
dump(settings($branch)->fallbackTo($company)->fallbackToGlobal()->explainValue('theme'));
// ['found' => true, 'source' => 'App\Models\Company:12', 'inherited' => true, ...]
```
