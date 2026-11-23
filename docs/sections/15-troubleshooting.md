## Troubleshooting

### Every request gets 401 idempotency_unauthenticated

The default scope needs an authenticated user. Add authentication to the route (`auth:api`, `auth:sanctum`), or configure a `ScopeResolver` for public endpoints.

### Every request gets 503 idempotency_store_unavailable

The store cannot be reached or the table is missing. Publish the migration with `php artisan vendor:publish --tag=api-idempotency-migrations` and run `php artisan migrate`, or check the store's connection name. The log has the underlying exception.

### Retries keep getting 409 idempotency_outcome_unknown

The first request returned a 5xx, threw, timed out, or returned a response that cannot be replayed. Reconcile it as described in [Failures and Recovery](#failures-and-recovery). If the route returns HTML, files or redirects, it should not be idempotent; JSON responses need an `application/json` or `+json` type.

### Retries get 409 idempotency_in_progress long after the first request finished

The first worker died or exceeded the lease. After `lease` seconds it becomes an unknown outcome. Raise `lease` above your slowest request if requests are just slow.

### A correct retry gets 409 idempotency_key_conflict

Something in the request changed: a timestamp in the body or a query parameter. Have the client resend the exact same request, and keep volatile values out of the body.

### The same request runs twice

Check that the retry arrived after the TTL, that both requests resolved the same scope (same user or tenant), the same route and the same method, and that your Redis is persistent.

### The idempotency_keys table keeps growing

Schedule `php artisan idempotency:prune --apply`. Unknown and abandoned records are kept until you add `--unresolved`.
