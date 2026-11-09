---
name: Settings

action:
  label: View on Packagist
  href: "{package.packagistUrl}"

views: components.packages

breadcrumbs:
  - label: Home
    href: route:home
  - label: Packages
    href: route:packages.index
  - label: "{technology.name} Packages"
    href: "url:/packages/{technology.slug}"
  - label: "{package.name}"

card:
  topic: configuration
  icon: database
  tags: settings preferences typed settings user settings tenant settings company settings eloquent model scopes fallback validation cache
  description: Typed settings for the whole application, any named scope, or any Eloquent model, stored through a real Setting model with explicit fallback, definitions and validation.

seo:
  title: "{package.fullName} - Typed Settings Package"
  description: "{package.name} is a Laravel package for typed application settings, global, named or per model, stored through an Eloquent Setting model with explicit fallback and validation. {package.downloadsShort}+ downloads, {package.license} licensed."
  keywords: laravel settings, laravel preferences, typed settings, user settings, tenant settings, eloquent settings, laravel package
  author: Pharaonic
  images:
    - "{package.cover}"
  openGraph:
    type: website
    siteName: Pharaonic
  twitter:
    card: summary_large_image

schema:
  "@type": SoftwareSourceCode
  name: "{package.name}"
  description: "{package.name} is a Laravel package for typed application settings in the global scope, named scopes or the scope of any Eloquent model, with explicit fallback, definitions, validation and a transaction-safe cache."
  image: "{package.cover}"
  codeRepository: "{package.githubUrl}"
  programmingLanguage: PHP
  runtimePlatform: "{technology.name}"
  version: "{package.version}"
  datePublished: "{package.publishedAt}"
  dateModified: "{package.updatedAt}"
  license: "https://opensource.org/licenses/{package.license}"
  isAccessibleForFree: true
  sameAs:
    - "{package.githubUrl}"
    - "{package.packagistUrl}"
  author:
    "@id": url:/#organization
  publisher:
    "@id": url:/#organization
  interactionStatistic:
    "@type": InteractionCounter
    interactionType: https://schema.org/DownloadAction
    userInteractionCount: "{package.downloads}"
---
