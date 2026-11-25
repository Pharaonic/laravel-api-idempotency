# Changelog

All notable changes to this project are documented here.

## 7.1.0 - Unreleased

### Added

- `IdempotencyException::response()`, the JSON response the package sends for a refusal.
- Translated error messages in the `api-idempotency` namespace, English and Arabic, published with the `api-idempotency-translations` tag, and `IdempotencyException::localizedMessage()`. The rendered `message` follows the application locale; `getMessage()` and `code` stay the same.

### Behavior Changes from 7.0.x

- In a locale other than English, the error message is sent in that locale when the package has a line for it, Arabic included. English applications see no change.

## 7.0.0 - 2026-11-23

### Compatibility

- Laravel 7.30+ on PHP 8.0 (`illuminate/*` ^7.30).
- The same API and behavior as 6.0.0.
- `pharaonic/php-fingerprint` ~8.0.3.

## 6.0.0 - 2026-11-23

### Added

- `Route::...->idempotent(ttl:)` macro, also on `Route::resource()` and `apiResource()`, and the `idempotent` middleware alias.
- `EnsureIdempotency` middleware: `Idempotency-Key` validation, scoped storage keys, request fingerprints through `pharaonic/php-fingerprint`, atomic reservation, response replay with an `Idempotency-Replayed` header, and `409` for key conflicts, requests in progress and unknown outcomes.
- Database store (publishable migration for `idempotency_keys`, never run until published) and Redis store (Lua scripts, phpredis and predis), chosen with `store.driver`.
- `ScopeResolver` contract with the default `AuthenticatedUser` scope.
- Fail-closed handling of server errors, exceptions, unstorable responses, abandoned leases and store outages: an uncertain outcome never runs again by itself.
- `IdempotentRequestReserved`, `IdempotentRequestReplayed`, `IdempotentRequestRejected` and `IdempotentRequestUnresolved` events.
- `idempotency:prune` command.

### Compatibility

- PHP 8.0.
- Laravel 6.20 and later 6.x releases (`illuminate/*` ^6.20).
- `pharaonic/php-fingerprint` ~8.0.3.
