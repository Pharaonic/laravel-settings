---
view: components.packages.quick-look
title: A quick look
subtitle: Store typed values per scope and read them with an explicit fallback chain.
file: app/Http/Controllers/BranchController.php
language: php
code: |
  settings()->setValue('theme', 'light');            // global
  $company->setSetting('theme', 'dark');             // company scope, saved right away
  settings('checkout')->setValue('minimum', 100);    // stays an integer

  $theme = settings($branch)
      ->fallbackTo($company)
      ->fallbackToGlobal()
      ->getValue('theme', 'system');                 // "dark"

  settings($branch)->fallbackTo($company)->explainValue('theme');
  // ['found' => true, 'source' => 'company:12', 'inherited' => true, ...]
---
