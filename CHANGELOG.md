# Changelog

All notable changes to this project are documented here.

## 13.1.0 - Unreleased

### Added

- `IdempotencyException::response()`, the JSON response the package sends for a refusal.
- Translated error messages in the `api-idempotency` namespace, English and Arabic, published with the `api-idempotency-translations` tag, and `IdempotencyException::localizedMessage()`. The rendered `message` follows the application locale; `getMessage()` and `code` stay the same.

### Fixed

- An application's own `renderable()` or `$exceptions->render()` callback for `IdempotencyException`, or one for every exception, now runs. Before, the exception's `render()` answered first, so the documented custom format was never sent. Without such a callback the response is the same.

### Behavior Changes from 13.0.x

- In a locale other than English, the error message is sent in that locale when the package has a line for it, Arabic included. English applications see no change.

## 13.0.0 - 2026-11-23

### Compatibility

- Laravel 13 on PHP 8.3, 8.4 and 8.5 (`illuminate/*` ^13.0). PHP 8.2 is no longer supported on this line.
- The same API and behavior as 12.0.0.
- `pharaonic/php-fingerprint` ~8.3.0, ~8.4.0 or ~8.5.0, matching the PHP version.
- The tests run on Pest 4 and PHPUnit 12.

## 12.0.0 - 2026-11-23

### Compatibility

- Laravel 12 on PHP 8.2, 8.3, 8.4 and 8.5 (`illuminate/*` ^12.0).
- The same API and behavior as 11.0.0.
- `pharaonic/php-fingerprint` ~8.2.0, ~8.3.0, ~8.4.0 or ~8.5.0, matching the PHP version.
- Laravel 12 still receives security fixes, so no advisory is ignored.

## 11.0.0 - 2026-11-23

### Compatibility

- Laravel 11 on PHP 8.2, 8.3 and 8.4 (`illuminate/*` ^11.0). PHP 8.1 is no longer supported on this line.
- The same API and behavior as 10.0.0.
- `pharaonic/php-fingerprint` ~8.2.0, ~8.3.0 or ~8.4.0, matching the PHP version.
- The tests run on Pest 3 and PHPUnit 11.

## 10.0.0 - 2026-11-23

### Added

- Laravel Boost resources: a guideline in `resources/boost/guidelines/core.blade.php` and the `api-idempotency-development` skill, picked up by `php artisan boost:install`.

### Compatibility

- Laravel 10 on PHP 8.1, 8.2 and 8.3 (`illuminate/*` ^10.0). PHP 8.0 is no longer supported on this line.
- The same API and behavior as 9.0.0.
- `pharaonic/php-fingerprint` ~8.1.0, ~8.2.0 or ~8.3.0, matching the PHP version.

## 9.0.0 - 2026-11-23

### Compatibility

- Laravel 9.33+ on PHP 8.0, 8.1 and 8.2 (`illuminate/*` ^9.33). 9.33 is the first 9.x release that requires a Carbon release working on PHP 8.2.
- The same API and behavior as 8.0.0.
- `pharaonic/php-fingerprint` ~8.0.3, ~8.1.0 or ~8.2.0, matching the PHP version.

## 8.0.0 - 2026-11-23

### Compatibility

- Laravel 8.75+ on PHP 8.0 and 8.1 (`illuminate/*` ^8.75).
- The same API and behavior as 7.0.0.
- `pharaonic/php-fingerprint` ~8.0.3 on PHP 8.0 and ~8.1.0 on PHP 8.1.

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
