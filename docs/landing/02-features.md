---
view: components.packages.features
variant: compact
badge: Key Features
title: Everything a retry-safe API needs
subtitle: One route macro, a migration, and your POST endpoints survive retries, double clicks and flaky networks.
items:
  - icon: switch
    title: One Route Macro
    text: "`Route::post(...)->idempotent()` keeps the fluent chain and works with groups, `apiResource()` and `route:cache`."
  - icon: lock
    title: Atomic Reservation
    text: A unique insert on MySQL, MariaDB, PostgreSQL or SQLite, or a Lua script on Redis. Two simultaneous requests never both run.
  - icon: check-circle
    title: Exact Replay
    text: "Retries get the stored status, body and allow-listed headers, plus `Idempotency-Replayed: true`. Cookies never replay."
  - icon: code-brackets
    title: Request Fingerprints
    text: Built on `pharaonic/php-fingerprint`. Key order does not matter; types and list order do. Reusing a key for another request is a `409`.
  - icon: users
    title: Scoped Per Principal
    text: Keys belong to the authenticated user or your own tenant resolver, so one client never sees another's response.
  - icon: shield-check
    title: Fail Closed
    text: Crashes, 5xx responses and store outages never lead to a silent second run. Unknown outcomes stay locked until you decide.
---
