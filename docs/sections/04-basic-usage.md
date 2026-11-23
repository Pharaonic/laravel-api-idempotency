## Basic Usage

Call `idempotent()` on any route. It returns the same route, so the chain continues.

```php title="routes/api.php"
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store')->idempotent();
});
```

### Per-route TTL

`ttl` is optional, in seconds, and works positionally or by name.

```php title="routes/api.php"
Route::post('payments', [PaymentController::class, 'store'])->idempotent(ttl: 604800);
```

A TTL outside 1 to 31536000 throws when the route is defined.

### Resources and groups

`apiResource()` and `resource()` accept the macro too. Safe methods (`GET`, `HEAD`, `OPTIONS`, `TRACE`) always pass through, so only `store`, `update` and `destroy` are protected.

```php title="routes/api.php"
Route::apiResource('invoices', InvoiceController::class)->idempotent();
```

For a whole group, use the `idempotent` middleware alias, with the TTL as its parameter:

```php title="routes/api.php"
Route::middleware(['auth:api', 'idempotent:600'])->group(function () {
    Route::post('exports', [ExportController::class, 'store']);
});
```

### What clients send

```http title="HTTP" no-copy
POST /api/orders
Authorization: Bearer <token>
Idempotency-Key: 6f1c3a52-8f2e-4d0b-9a7e-1b2c3d4e5f60
Content-Type: application/json

{"product_id": 15, "quantity": 2}
```

- First request: your controller runs, for example `201 {"id":101,"status":"created"}`.
- Same key, same request, within the TTL: the same status and body, plus `Idempotency-Replayed: true`. The controller does not run.
- Same key, `"quantity": 5`: `409` with `idempotency_key_conflict`.
- Same key while the first request still runs: `409` with `idempotency_in_progress` and `Retry-After`.

A key is 1 to 255 visible ASCII characters. A UUID per user action is a good choice.

### Authentication runs first

The package puts its middleware after Laravel's authentication, route model binding and authorization in the router's middleware priority, so `->idempotent()->middleware('auth:api')` still authenticates first. Requests refused by those middleware never reserve a key.

### Route caching

The macro only adds a middleware string such as `Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency:600`, so `php artisan route:cache` keeps it exactly.
