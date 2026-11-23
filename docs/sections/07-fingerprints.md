## Fingerprints

The request fingerprint is a SHA-256 built with `pharaonic/php-fingerprint` from:

- the method, path and query string;
- the decoded JSON body.

Headers are not part of it, so a new trace ID or a refreshed token never turns a retry into a conflict.

Raw bodies are never stored; only their fingerprint is.

### JSON rules

| Compared | Result |
| --- | --- |
| `{"a":1,"b":2}` and `{"b":2,"a":1}` | same: object key order never matters |
| `{"a":[1,2]}` and `{"a":[2,1]}` | different: lists keep their order |
| `1`, `1.0` and `"1"` | all different |
| `true` and `1` | different |
| `{}` and `[]` | different |
| `{"a":null}` and `{}` | different |
| `1e2` and `100.0` | same: both decode to the same float |
| integers beyond PHP_INT_MAX | compared by their digits |

### Supported bodies

Requests must have a JSON body (`application/json` or any `+json` type) or no body. Form and multipart requests get `415 idempotency_request_unsupported`; invalid JSON, or JSON nested deeper than `json_decode()` allows, gets `400 idempotency_request_invalid`. Limit body sizes where you already do: your web server and `post_max_size`.

### Volatile fields and API versions

A retry must resend the exact same request, so keep client timestamps and other volatile values out of the body.

Put API versions in the URL: `/v1/orders` and `/v2/orders` are different routes, so they never share keys. A version sent only in a header is not part of the fingerprint, so a retry under another version gets the first response replayed.
