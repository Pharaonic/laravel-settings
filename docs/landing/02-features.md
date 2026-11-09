---
view: components.packages.features
variant: compact
badge: Key Features
title: Everything you need for typed, scoped settings
subtitle: Add one trait to a model, or call settings(), and start storing values.
items:
  - icon: check-circle
    title: Typed Values
    text: Each setting stores its type and nullability; `"20"` never sneaks into an integer setting.
  - icon: building
    title: Global, Named and Model Scopes
    text: "`settings()`, `settings('checkout')` and `settings($company)` share one table, kept unique by the database."
  - icon: arrow-long-down
    title: Explicit Fallback
    text: "`fallbackTo()` reads branch, then company, then global; a stored `null` stops the chain."
  - icon: database
    title: Plain Eloquent
    text: "`Setting::for($company)->where(...)->get()` returns `Setting` models, as Eloquent always does."
  - icon: shield-check
    title: Definitions and Validation
    text: Declare the type, default and Laravel rules of important keys once, for every scope.
  - icon: bolt
    title: Transaction-Safe Cache
    text: Each scope is cached on its own and forgotten only after the outermost transaction commits.
---
