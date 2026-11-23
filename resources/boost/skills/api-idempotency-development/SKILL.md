---
name: api-idempotency-development
description: Making Laravel API routes safe to retry with pharaonic/laravel-api-idempotency, covering the idempotent() macro and middleware, configuration, scopes, stores, replay rules, error codes, events, pruning and testing. Use when adding, debugging or testing Idempotency-Key handling on POST, PUT, PATCH or DELETE endpoints.
---

# Laravel API Idempotency Development

## When to use this skill

Use this skill when a route must not run twice for one client action (orders, payments, emails, any side effect), when a client gets an `idempotency_*` error, or when you write tests for idempotent endpoints.

## Setup

```bash
composer require pharaonic/laravel-api-idempotency

# Database store (the default): publish the migration, then migrate.
php artisan vendor:publish --tag=api-idempotency-migrations
php artisan migrate

# Optional: publish the configuration to config/pharaonic/api-idempotency.php.
php artisan vendor:publish --tag=api-idempotency-config
```

For Redis, set `IDEMPOTENCY_STORE=redis` and skip the migration. `IDEMPOTENCY_CONNECTION` names the database or Redis connection (the default one when empty).

## Marking routes

```php
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OrderController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])->idempotent();      // configured ttl
    Route::post('orders/bulk', [OrderController::class, 'bulk'])->idempotent(600); // 10 minutes
    Route::apiResource('invoices', InvoiceController::class)->idempotent();       // store, update, destroy
});

Route::middleware(['auth:sanctum', 'idempotent:600'])->group(function () {
    // every unsafe route in the group, with a 600 second ttl
});
```

- `idempotent(?int $ttl = null)` exists on `Route` and on `Route::resource()` / `apiResource()`, returns the same object, and throws `InvalidArgumentException` for a ttl outside 1 to 31536000 when the route is defined.
- The middleware alias is `idempotent`, class `Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency`, with one optional parameter: the ttl.
- The package runs its middleware after authentication, route model binding and authorization, even when `->middleware('auth:...')` is added after `->idempotent()`.
- Routes stay compatible with `php artisan route:cache`.

## What clients send and get

- One `Idempotency-Key` header, 1 to 255 visible ASCII characters; generate one per user action and reuse it on every retry of that action.
- Bodies must be JSON (`application/json` or any `+json` type) or empty.
- The fingerprint is the method, path, query string and decoded JSON body (key order ignored, types and list order kept). Headers are not part of it.
- First request: the route runs. Same key and same request within the ttl: the stored status, body and `Content-Type`, `Location`, `Content-Location`, `ETag`, `Last-Modified` headers, plus `Idempotency-Replayed: true`; the route does not run.

## Configuration (`pharaonic.api-idempotency`)

| Key | Default | Meaning |
| --- | --- | --- |
| `ttl` | `86400` | Seconds a finished request stays replayable, counted from when it finished. |
| `lease` | `120` | Seconds a request may run before it counts as abandoned. Keep it above your slowest request and `max_execution_time`. |
| `scope` | `Scopes\AuthenticatedUser::class` | A `ScopeResolver` class. |
| `store.driver` | `env('IDEMPOTENCY_STORE', 'database')` | `database` or `redis`; anything else throws. |
| `store.connection` | `env('IDEMPOTENCY_CONNECTION')` | Connection name, `null` for the default. |
| `store.table` | `idempotency_keys` | Database store table. |
| `store.prefix` | `idempotency:` | Redis key prefix. |
| `max_response_bytes` | `1048576` | Largest response body stored. |

Invalid values throw `InvalidArgumentException` on the first idempotent request; nothing runs unprotected.

## Scopes

`Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver::resolve(Request $request): ?string` decides whose key space a request uses. The default `Scopes\AuthenticatedUser` returns the user's class and identifier; `null` means no principal and the request gets `401`. A custom resolver must identify one authenticated principal (never a constant or a shared IP), and is resolved from the container on every request.

## Outcomes

| The route returned | Record | A retry with the same key gets |
| --- | --- | --- |
| A `2xx` with a JSON, `+json` or empty body within `max_response_bytes` | completed | the stored response |
| Any other `4xx` | released | a new run |
| A `5xx`, a thrown exception, or a success that cannot be stored (HTML, file, stream, redirect, too large) | unknown | `409 idempotency_outcome_unknown` until the ttl ends |

A request still running gets `409 idempotency_in_progress` with `Retry-After`; after its `lease`, an unfinished request also becomes `idempotency_outcome_unknown`. Nothing runs again automatically: reconcile with your own data, then delete the row (database store) if the client may retry.

## Errors

`Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException` (an `HttpException`) renders `{"message": "...", "code": "..."}`. `errorCode()` returns one of its constants:

| Status | Code | When |
| --- | --- | --- |
| 400 | `idempotency_key_missing` | No key header. |
| 400 | `idempotency_key_invalid` | Malformed or repeated key. |
| 401 | `idempotency_unauthenticated` | The scope resolver returned `null`. |
| 409 | `idempotency_key_conflict` | The key was used with a different request. |
| 409 | `idempotency_in_progress` | The first request still runs (`Retry-After`). |
| 409 | `idempotency_outcome_unknown` | The first request's outcome is unknown. |
| 415 | `idempotency_request_unsupported` | The body is not JSON. |
| 400 | `idempotency_request_invalid` | Invalid or too deeply nested JSON. |
| 503 | `idempotency_store_unavailable` | The store failed before the route ran. |

Render it in your exception handler for a custom error envelope; replays are never changed.

## Events

All in `Pharaonic\Laravel\ApiIdempotency\Events`, with `$request` and `$key` (the SHA-256 storage key, never the client's key):

- `IdempotentRequestReserved`: the key was reserved and the route is about to run.
- `IdempotentRequestReplayed`: a stored response was returned.
- `IdempotentRequestRejected`: refused; `$reason` is the error code.
- `IdempotentRequestUnresolved`: the outcome could not be stored; `$reason` is `server_error`, `exception`, `unstorable_response` or `store_failed`. Listen to it to alert someone.

## Pruning

```bash
php artisan idempotency:prune                         # counts expired completed records
php artisan idempotency:prune --apply                 # deletes them
php artisan idempotency:prune --apply --unresolved    # also expired unknown and abandoned records
```

Schedule it daily for the database store; Redis records expire by themselves.

## Pitfalls

- Never put a timestamp or random value in the body of a retried request; the retry becomes `idempotency_key_conflict`.
- An API version sent only in a header is not part of the fingerprint; version in the URL.
- After the ttl, the same key runs again. Choose a ttl longer than any client retries.
- The idempotency record is written outside your transaction. Give the business record a unique reference and pass an idempotency key to payment providers.
- Point the store at its own database connection when controllers use transactions on the default one.
- A Redis store without persistence forgets records on restart, and retries then run again.

## Testing

Send the header with Laravel's HTTP test helpers and assert the replay:

```php
$headers = ['Idempotency-Key' => 'order-1'];

$this->actingAs($user)->postJson('/api/orders', ['product_id' => 15], $headers)->assertCreated();
$this->actingAs($user)->postJson('/api/orders', ['product_id' => 15], $headers)
    ->assertCreated()
    ->assertHeader('Idempotency-Replayed', 'true');
$this->actingAs($user)->postJson('/api/orders', ['product_id' => 16], $headers)
    ->assertStatus(409)
    ->assertJson(['code' => 'idempotency_key_conflict']);

expect(Order::count())->toBe(1);
```

Run the published migration in the test database, or set `IDEMPOTENCY_STORE` to the store your tests use. Use `Event::fake()` to assert the events, and move time with `$this->travel()` to cross the ttl or lease.
