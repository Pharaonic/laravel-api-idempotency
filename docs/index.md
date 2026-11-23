---
name: API Idempotency

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
  topic: api
  icon: shield-check
  tags: api idempotency idempotency-key retries replay redis database concurrency payments ai-ready laravel-boost
  description: Opt-in HTTP idempotency for Laravel routes. Add ->idempotent() to a route and retried requests with the same Idempotency-Key replay the stored response instead of running twice.

seo:
  title: "{package.fullName} - Idempotency-Key Middleware for Laravel APIs"
  description: "{package.name} makes Laravel POST routes safe to retry: atomic key reservation on the database or Redis, request fingerprints, response replay and fail-closed recovery. {package.license} licensed."
  keywords: laravel idempotency, idempotency key, idempotent api, laravel middleware, retry safe api, redis, payments, duplicate requests, laravel boost, ai ready
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
  description: "{package.name} is a Laravel package that adds Idempotency-Key handling to routes with atomic reservation and safe response replay."
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
