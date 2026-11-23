## API Reference

### Route macro

| Method | Description | Returns |
| --- | --- | --- |
| `Route::...->idempotent(?int $ttl = null)` | Adds the idempotency middleware to the route. Throws `InvalidArgumentException` for an invalid TTL. | The same `Route` |
| `Route::resource(...)` / `apiResource(...)->idempotent(?int $ttl = null)` | The same for every route of the resource. | The same `PendingResourceRegistration` |

### Middleware

| Name | Description |
| --- | --- |
| `idempotent` | Alias of `EnsureIdempotency`; optional parameter `idempotent:{ttl}`. |
| `Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency` | The middleware class, placed after authentication, route model binding and authorization in the router's priority. |

### Contracts and classes

| Class | Description |
| --- | --- |
| `Contracts\ScopeResolver` | `resolve(Request $request): ?string`, the key space of a request. |
| `Scopes\AuthenticatedUser` | Default resolver: the authenticated user's class and identifier. |
| `Exceptions\IdempotencyException` | Refused request. `errorCode(): string`, `getStatusCode(): int`, `getHeaders(): array`, `render(): JsonResponse`, and one constant per code. |
| `Events\IdempotentRequestReserved` | `$request`, `$key` |
| `Events\IdempotentRequestReplayed` | `$request`, `$key` |
| `Events\IdempotentRequestRejected` | `$request`, `$key`, `$reason` |
| `Events\IdempotentRequestUnresolved` | `$request`, `$key`, `$reason` |

All classes are in the `Pharaonic\Laravel\ApiIdempotency` namespace.

### Artisan

| Command | Description |
| --- | --- |
| `idempotency:prune` | Counts expired completed records of the database store. The Redis store needs no pruning. |
| `--apply` | Deletes them. |
| `--unresolved` | Also expired `unknown` and abandoned `processing` records. |

### Table

`idempotency_keys`: `storage_key` (primary), `request_fingerprint`, `state` (`processing`, `completed`, `unknown`), `owner_token`, `method`, `route`, `created_at`, `lease_expires_at`, `expires_at` (indexed), `completed_at`, `response_status`, `response_headers`, `response_body`. Times are Unix timestamps.

### Publish tags

| Tag | Publishes |
| --- | --- |
| `api-idempotency-config` | `config/pharaonic/api-idempotency.php` |
| `api-idempotency-migrations` | The migration, to `database/migrations` |
| `pharaonic`, `laravel-api-idempotency` | Both |
