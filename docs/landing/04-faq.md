---
view: components.home.faq
badge: FAQ
title: "{package.name}"
highlight: Questions
subtitle: "Quick answers about installing and using {package.name}."
---

## What is {package.name}?

{card.description} It's a free, open-source {technology.name} package by Pharaonic.

## How do I install {package.name}?

Run `composer require {package.composer}` in your project's root directory, then `php artisan migrate`.

## What does {package.name} require?

The latest release requires {package.requiresText}.

## How is it different from Laravel Dynamic Config?

Settings stores application preferences that your code reads with `getValue()`. Laravel Dynamic Config stores values that it merges into Laravel's own `config()`. They use separate tables and never touch each other, so you can use both.

## Does a model scope follow my morph map?

Yes. A model's scope is its morph class and key, such as `company:12`, so `settings($company)` and `settings('company:12')` are the same rows.

## How do I upgrade from version 1.x?

Run `php artisan settings:migrate-legacy` for a report, then again with `--apply`. It converts the old table side by side and keeps a backup.

## Is {package.name} free to use?

Yes. {package.name} is open source under the {package.license} license, so you can use it in personal and commercial projects.

## Where can I find the {package.name} documentation?

Read the [{package.name} documentation]({package.docsUrl}) for setup, configuration, and usage examples.

## How do I report a bug or contribute to {package.name}?

Open an issue or a pull request on [GitHub]({package.githubUrl}), ask in [GitHub Discussions](https://github.com/orgs/Pharaonic/discussions), or chat with us on [Discord](https://discord.gg/XQG9RhvEvf).
