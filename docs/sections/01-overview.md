:::badges
- Laravel Package {color=blue}
- {release.label} {color=green}
- {package.license} License {color=purple}
:::

# API Idempotency

API Idempotency makes unsafe Laravel routes safe to retry. Add `->idempotent()` to a route; clients send an `Idempotency-Key` header. The first request reserves the key atomically and runs. A retry with the same key and the same request gets the stored response back without running your controller again. The same key with a different request, a duplicate that arrives while the first is still running, and a request whose outcome is unknown are all refused with a clear `409`.

:::features
### One Route Macro {icon="switch"}
Route::post(...)->idempotent() keeps the fluent chain and survives route caching.

### Atomic Reservation {icon="lock"}
A unique insert on the database, or one Lua script on Redis. Never a read followed by a write.

### Exact Replay {icon="check-circle"}
The stored status, body and allow-listed headers, without cookies or credentials.

### Request Fingerprints {icon="code-brackets"}
Method, path, query and JSON body through pharaonic/php-fingerprint, with strict types.

### Scoped Keys {icon="users"}
Keys belong to one user or tenant; nobody sees another principal's response.

### Fail Closed {icon="shield-check"}
Crashes, server errors and store outages never cause a silent second run.
:::

:::warning Not exactly-once
No HTTP layer can promise exactly-once side effects. This package guarantees that one key never runs twice at the same time and that an uncertain outcome is never retried automatically. For payments, also store a unique business reference and pass an idempotency key to your payment provider.
:::
