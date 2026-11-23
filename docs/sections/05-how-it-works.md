## How It Works

Every unsafe request on an idempotent route goes through these steps, in this order:

1. **Key**: the header must hold one valid key, or the request gets `400`.
2. **Scope**: the scope resolver must name a principal, or the request gets `401`.
3. **Body**: the body must be empty or JSON within the limits, or it gets `415`, `413` or `400`.
4. **Reservation**: the store atomically inserts a `processing` record for the storage key, with a random owner token.
5. **Existing record**: if the key is taken, the request is answered from that record (see below) and your route does not run.
6. **Route**: your controller runs once.
7. **Outcome**: the owner records the outcome with a write that only succeeds while it still owns the record.

### States

```text title="Record states" no-copy
ABSENT --reserve--> PROCESSING --stored response--> COMPLETED --ttl--> ABSENT
                        |   \--4xx not listed-----> ABSENT (released)
                        \--5xx, exception,
                           unstorable response----> UNKNOWN --ttl--> ABSENT
```

### Answering an existing record

| Stored record | Same fingerprint | Different fingerprint |
| --- | --- | --- |
| `completed` | Replay the stored response | `409 idempotency_key_conflict` |
| `processing`, lease running | `409 idempotency_in_progress` with `Retry-After` | `409 idempotency_key_conflict` |
| `processing`, lease expired | `409 idempotency_outcome_unknown` | `409 idempotency_key_conflict` |
| `unknown` | `409 idempotency_outcome_unknown` | `409 idempotency_key_conflict` |

### Times

- A completed record expires `ttl` seconds after it **finished**. At that second the key is new again.
- A processing record holds its lease for `lease` seconds and is kept at least `ttl` seconds from when it started.
- An unknown record expires `ttl` seconds after its outcome was recorded.

Times use your application's clock (`Carbon::now()`), so keep servers in sync with NTP.

:::danger After the TTL, a key is new
A retry that arrives after the TTL runs your route again. Choose a TTL longer than any client retries, and never rely on this package alone to prevent duplicate charges.
:::
