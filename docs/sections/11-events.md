## Events and Logs

The middleware dispatches four events. Each has public `$request` and `$key` properties; `$key` is the SHA-256 storage key, which never reveals the client's key or identity.

| Event | Dispatched when | Extra |
| --- | --- | --- |
| `IdempotentRequestReserved` | A request reserved its key and your route is about to run. | |
| `IdempotentRequestReplayed` | A stored response was returned instead of running the route. | |
| `IdempotentRequestRejected` | A request was refused because of its key's record. | `$reason`: `idempotency_key_conflict`, `idempotency_in_progress` or `idempotency_outcome_unknown` |
| `IdempotentRequestUnresolved` | A request ran but its outcome could not be stored. | `$reason`: `server_error`, `exception`, `unstorable_response` or `store_failed` |

All four live in `Pharaonic\Laravel\ApiIdempotency\Events`. Requests refused before reservation (bad key, no scope, bad body) dispatch nothing.

```php title="app/Providers/EventServiceProvider.php"
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestUnresolved;

public function boot()
{
    parent::boot();

    Event::listen(IdempotentRequestUnresolved::class, function (IdempotentRequestUnresolved $event) {
        Log::channel('alerts')->warning('Idempotent request needs reconciliation', [
            'key' => $event->key,
            'route' => $event->request->path(),
            'reason' => $event->reason,
        ]);
    });
}
```

### Logs

The package logs only problems: store failures (`error`), unknown outcomes and lost ownership (`warning`). Log context holds the first 16 characters of the storage key, the method and the route pattern, and for store failures the exception's class and code (and its message, except for database errors, whose SQL would carry the stored response); never the client's key, the user, the request body or the response.
