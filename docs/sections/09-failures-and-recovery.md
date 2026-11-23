## Failures and Recovery

The package never assumes that a request without a stored outcome did nothing. When it cannot know, it keeps the key locked.

### What each outcome does

| Your route returned | Record | A retry with the same key gets |
| --- | --- | --- |
| A storable `2xx` | `completed` | The stored response |
| Any other `4xx` | released (deleted) | A new run, so a corrected request can succeed |
| A `5xx`, or a thrown exception | `unknown` | `409 idempotency_outcome_unknown` |
| A success that cannot be stored (HTML, streamed, file download, too large, non-UTF-8, a redirect) | `unknown` | `409 idempotency_outcome_unknown` |

A `2xx` response is storable when its `Content-Type` is `application/json` or any `+json` type (or its body is empty), and its body is valid UTF-8 within `max_response_bytes`. Your client still receives the original response the first time.

### Crashes and timeouts

A worker that dies after your side effect and before the outcome is stored leaves a `processing` record:

- until its `lease` ends, retries get `409 idempotency_in_progress` with `Retry-After`;
- after the lease, retries get `409 idempotency_outcome_unknown` until the record expires or you remove it. The route never runs again by itself.

The original worker can never overwrite a record it no longer owns: its final write is conditional on its owner token and is dropped with a warning in the log.

Set `lease` above your longest request time (and above PHP's `max_execution_time`), so a slow request is not mistaken for an abandoned one.

### Resolving an unknown outcome

1. Find the records: in the database store, `state = 'unknown'`, or `state = 'processing'` with `lease_expires_at` in the past. The `method` and `route` columns say which endpoint they belong to.
2. Reconcile with your own data: did the order, charge or email happen?
3. If it did not happen and the client should be able to retry, delete the record. If it did, leave it; the client keeps getting `409` until it expires and should look the result up instead of retrying.

Listen to `IdempotentRequestUnresolved` (see [Events](#events)) to alert someone when this happens.

### Transactions and side effects

The idempotency record is written outside your controller's database transaction, before and after it runs, and nothing makes it atomic with your own writes, Redis, queues or external APIs. For strong guarantees:

- give the business record a unique reference (for example the idempotency key itself, or an order number) and enforce it with a unique index;
- send an idempotency key to providers that support one (most payment gateways do);
- dispatch jobs and emails after your own transaction commits.
