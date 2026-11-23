# Maintaining This Package

This file records package-specific maintainer facts that are safe to keep in the public repository.

## Purpose

Opt-in HTTP idempotency for Laravel routes: `Route::...->idempotent()` adds a middleware that validates the `Idempotency-Key` header, reserves a scoped key atomically in the database or Redis, runs the route once, and replays its stored response to retries. Uncertain outcomes are locked, never run again automatically.

## Supported Laravel lines

One branch per Laravel major, from `6.x` to the newest major, all carrying the same package code. Each line's PHP range, `illuminate/*` constraint, and tools follow the Pharaonic Laravel line table. `6.x` runs PHPUnit, because Pest cannot install on Laravel 6; later lines run Pest. `develop` follows the newest line.

Per-line differences beyond constraints and tooling: docs examples follow each line's conventions (`'Controller@method'` routes before 8.x), and from `10.x` the Laravel Boost resources. `9.x` requires `illuminate/*` ^9.33, the first 9.x release that requires a Carbon release working on PHP 8.2 (9.0 fails there with lowest dependencies).

## Core library

`pharaonic/php-fingerprint`, one `~8.N.p` per PHP minor of the line. It builds both hashes: the request fingerprint (`Fingerprint::for()->namespace()->ignore()->maxDepth()->make()`) and the storage key. Canonicalization rules (key order, strict types, list order) belong there, not here.

## Public API invariants

Configuration keys (`pharaonic.api-idempotency.*`), publish tags, and registered Blade, Artisan, macro, and validation names are public API.

- The `idempotent()` macro on `Illuminate\Routing\Route` and `PendingResourceRegistration`, its `ttl` parameter, and the `idempotent` middleware alias with its positional `{ttl}` parameter.
- `Http\Middleware\EnsureIdempotency`, `Contracts\ScopeResolver`, `Scopes\AuthenticatedUser`, `Exceptions\IdempotencyException` (its constants, `errorCode()` and JSON shape `{"message", "code"}`), the four events with their properties and `reason` values, and `idempotency:prune` with `--unresolved` and `--apply`.
- The error codes and their statuses, the `Idempotency-Key` and `Idempotency-Replayed` headers, the replayed response headers, and the `idempotency_keys` table columns.
- The request fingerprint and storage key formats (namespaces `pharaonic/laravel-api-idempotency:request` and `:key`). Changing either orphans every stored record, so retries in flight would run again: only in a new major, with a note.
- `Support\*`, `Stores\*` and `Routing\*` are `@internal`.

## Package-specific constraints

- Reservation is always one atomic step: `insertOrIgnore()` on the primary key, or one Lua script per key on Redis. Every later write is conditional on the owner token and the `processing` state. Never add a read-then-write path or a store driver without atomic reservation.
- Fail closed: store errors before the route runs give `503`; 5xx, exceptions and unstorable successes become `unknown`; an expired lease is `unknown` and never runs again by itself. Nothing deletes an unresolved record automatically except its TTL.
- Only 2xx responses are stored (JSON, `+json` or empty, within `max_response_bytes`); only 4xx responses release the key.
- Times are the application's `Carbon::now()` in whole seconds, passed into the Redis scripts, so tests can move time and servers must share a clock.
- No request state in singletons: the middleware, options, scope resolver and store are built per request (Octane).
- The middleware is inserted after `Authorize` (or `SubstituteBindings`) in the router's middleware priority at boot and again when the HTTP kernel is resolved.
- Logs and events never carry the client key, scope or body: only the SHA-256 storage key (truncated in logs), method and route pattern.
- The migration is publish-only (tag `api-idempotency-migrations`), never loaded with `loadMigrationsFrom()`: only the database store needs it, and an application on Redis should not get a table it never uses. This departs from the default Pharaonic migration rule on purpose. Tests run it by path (`TestCase::MIGRATIONS`).
- The prune command is `idempotency:prune`, not `api-idempotency:prune`: it names what it prunes, as Laravel's own `queue:prune-batches` and `model:prune` do. This departs from the `<name>:<action>` command rule on purpose.
- `mbstring` comes from php-fingerprint's own requirement.
- The config is deliberately small (`ttl`, `lease`, `scope`, one `store`, `max_response_bytes`). The key and replay header names, the stored statuses and types, the replayed headers, and the JSON depth are fixed rules, not options: each one could weaken the guarantees or only mirrors a limit the application already has. Add an option only for a concrete need, never by symmetry.

## Tests

- `tests/Integration` runs against real servers when `IDEMPOTENCY_TEST_MYSQL`, `IDEMPOTENCY_TEST_PGSQL` (JSON `host`, `port`, `username`, `password`, `database`) and `IDEMPOTENCY_TEST_REDIS_HOST` (`host:port`) are set, and skips them otherwise; SQLite always runs. CI provides MySQL, PostgreSQL and Redis services on Ubuntu.
- Concurrency, crash and route-cache tests start real PHP processes through `tests/Fixtures/harness.php`. The crash test needs `posix` and skips on Windows.
- `route:cache` runs Laravel's own command with only its fresh-application factory replaced (`Fixtures\FreshRouteCacheCommand`), because Testbench's `bootstrap/app.php` does not load the package. This works on every line, so no Testbench `remote()` floor is needed. The cache path comes from `APP_ROUTES_CACHE`.
- MySQL and PostgreSQL tests drop and recreate their tables: point them at a throwaway database, and do not run two suites against one database at once.

## Branch notes

Fixes land on the oldest affected line and are ported to every later line and `develop`.

## Documentation

https://pharaonic.dev/packages/laravel/api-idempotency

Keep this file factual and package-specific. General Pharaonic engineering policy belongs in the private engineering repository, not here.
