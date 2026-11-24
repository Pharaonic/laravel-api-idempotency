## Errors

Refused requests throw `Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException`, an `HttpException` that renders this JSON with its status:

```json title="Response" no-copy
{
    "message": "This Idempotency-Key was already used with a different request.",
    "code": "idempotency_key_conflict"
}
```

| Status | `code` | When |
| --- | --- | --- |
| 400 | `idempotency_key_missing` | The `Idempotency-Key` header is missing or empty. |
| 400 | `idempotency_key_invalid` | The key is longer than 255 characters, contains anything but visible ASCII, or is sent twice. |
| 401 | `idempotency_unauthenticated` | The scope resolver found no principal. |
| 409 | `idempotency_key_conflict` | The key was used with a different request. |
| 409 | `idempotency_in_progress` | The first request with this key is still running. Has `Retry-After`. |
| 409 | `idempotency_outcome_unknown` | The first request's outcome is unknown, so it will not run again. |
| 415 | `idempotency_request_unsupported` | The body is not JSON. |
| 400 | `idempotency_request_invalid` | The body is invalid JSON or nested deeper than `json_decode()` allows. |
| 503 | `idempotency_store_unavailable` | The store failed while reserving the key. |

Each code is also a constant on the exception (`IdempotencyException::KEY_CONFLICT`), and `errorCode()` returns it.

### Translated messages

The `message` is translated in the application locale from `api-idempotency::messages`, keyed by the code without its `idempotency_` prefix (`key_missing`, `key_conflict`, …). English and Arabic ship with the package, and a locale without a line falls back to English. The `code` never changes with the locale. `getMessage()` stays in English for your logs, and `localizedMessage()` returns the translated message.

Publish the files to change a line or add a language:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=api-idempotency-translations
```

### Your own error format

Render the exception yourself in your exception handler to match your API's envelope. Your `render()` override or `renderable()` callback always wins: the package renders its own JSON from a callback registered after yours.

```php title="app/Exceptions/Handler.php"
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Throwable;

public function render($request, Throwable $exception)
{
    if ($exception instanceof IdempotencyException) {
        return response()->json([
            'error' => ['code' => $exception->errorCode(), 'detail' => $exception->localizedMessage()],
        ], $exception->getStatusCode(), $exception->getHeaders());
    }

    return parent::render($request, $exception);
}
```

Replays are never changed: they carry your application's original body and status.
