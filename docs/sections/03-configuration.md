## Configuration

Every option lives under `pharaonic.api-idempotency`. Invalid values throw an `InvalidArgumentException` when a request uses them; nothing is silently corrected.

| Key | Default | Description |
| --- | --- | --- |
| `ttl` | `86400` | Seconds a finished request stays replayable, counted from when it finished. 1 to 31536000. Routes may override it. |
| `lease` | `120` | Seconds a request may run before it counts as abandoned. 1 to 86400. See [Failures and Recovery](#failures-and-recovery). |
| `scope` | `Scopes\AuthenticatedUser::class` | A `ScopeResolver` class that decides whose key space a request uses. |
| `store.driver` | `env('IDEMPOTENCY_STORE', 'database')` | `database` or `redis`. See [Stores](#stores). |
| `store.connection` | `env('IDEMPOTENCY_CONNECTION')` | The database or Redis connection name; `null` uses the default one. |
| `store.table` | `idempotency_keys` | The table of the database store. |
| `store.prefix` | `idempotency:` | The key prefix of the Redis store. |
| `max_response_bytes` | `1048576` | Largest response body that is stored and replayed. |

### Fixed rules

These are not options, because changing them would weaken the guarantees:

- The key travels in the `Idempotency-Key` header, and replays carry `Idempotency-Replayed: true`.
- Request bodies are JSON (`application/json` or any `+json` type) or empty; the method, path, query string and body make the fingerprint.
- Any `2xx` response with a JSON (or `+json`) type or an empty body is stored and replayed with its `Content-Type`, `Location`, `Content-Location`, `ETag` and `Last-Modified` headers. Cookies and credentials never replay.
- Any other `4xx` releases the key; everything else locks it as an unknown outcome.

:::tip Keep ttl and lease apart
`ttl` is how long a result can be replayed. `lease` is how long one execution may take. An expired lease never makes a request retryable by itself.
:::
