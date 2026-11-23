---
view: components.packages.package-hero
badges:
  - label: Laravel Package
    color: blue
  - label: "{package.latestVersionLabel}"
    color: green
  - label: "{package.license} License"
    color: purple
  - label: "{package.downloadsShort}+ downloads"
    color: blue
  - label: AI Ready · Laravel Boost
    color: violet
eyebrow: "{package.name}"
title: Retry safely,
highlight: run once
buttons:
  - label: View Full Documentation
    href: "{card.docsUrl}"
    style: primary
    icon: arrow-right
  - label: View on GitHub
    href: "{package.githubUrl}"
    style: ghost
    external: true
install: "{card.install}"
labels:
  copy: Copy
  copied: Copied!
---

{package.name} adds `Idempotency-Key` handling to any Laravel route with one call: `->idempotent()`. The first request reserves its key atomically and runs; retries replay the stored response, concurrent duplicates wait their turn, and uncertain outcomes stay locked instead of running twice. It is AI ready: `php artisan boost:install` teaches your coding agent the whole package through Laravel Boost.
