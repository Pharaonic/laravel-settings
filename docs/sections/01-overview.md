:::badges
- Laravel Package {color=blue}
- {release.label} {color=green}
- {package.license} License {color=purple}
:::

# Settings

Settings stores typed application settings in your database, for the whole application, a named scope such as `checkout`, or any saved Eloquent model such as a company, branch, or user. Every row is a real `Setting` Eloquent model that keeps its type, so an integer stays an integer, a boolean never turns into `"1"`, and a stored `null` is still a stored value. Reads fall back from one scope to another only when you ask them to.

:::features
### Typed Values {icon="check-circle"}
Each setting keeps its type and nullability, and a write never changes them silently.

### Global, Named and Model Scopes {icon="building"}
One identifier column addresses the global scope, any name, or any saved model.

### Explicit Fallback {icon="arrow-long-down"}
Branch, then company, then global, in the order you choose, with no hidden parent lookups.

### Plain Eloquent {icon="database"}
Setting is a normal Eloquent model, so get, where and orderBy work as usual.

### Definitions and Validation {icon="shield-check"}
Declare the type, default and Laravel validation rules of important keys once.

### Transaction-Safe Cache {icon="bolt"}
Each scope is cached on its own and forgotten only after your transaction commits.
:::

:::info Settings or Dynamic Config?
Settings is for application preferences that your code reads with `getValue()`. To change Laravel's own `config()` values at runtime, use [Laravel Dynamic Config](https://pharaonic.dev/packages/laravel/dynamic-config). The two packages can run side by side and never touch each other's table.
:::
