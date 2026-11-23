## Examples

### 1. Checkout that survives a double click

The browser generates one key per checkout attempt and reuses it on every retry.

- ===Routes

  ```php title="routes/api.php"
  Route::middleware('auth:api')->group(function () {
      Route::post('checkout', 'CheckoutController@store')->idempotent();
  });
  ```

- ===Client

  ```js title="resources/js/checkout.js"
  const key = crypto.randomUUID();

  async function checkout(cart) {
      const response = await fetch('/api/checkout', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key, Authorization: `Bearer ${token}` },
          body: JSON.stringify(cart),
      });

      if (response.status === 409 && response.headers.has('Retry-After')) {
          await new Promise(r => setTimeout(r, response.headers.get('Retry-After') * 1000));
          return checkout(cart);
      }

      return response.json();
  }
  ```

### 2. Payments with a provider key and a unique reference

Keep replays for a week, and protect the charge on both sides.

- ===Routes

  ```php title="routes/api.php"
  Route::post('payments', 'PaymentController@store')->middleware('auth:api')->idempotent(ttl: 604800);
  ```

- ===Controller

  ```php title="app/Http/Controllers/PaymentController.php"
  public function store(Request $request)
  {
      $reference = $request->user()->id.':'.$request->header('Idempotency-Key');

      $payment = Payment::firstOrCreate(['reference' => $reference], ['amount' => $request->input('amount')]);

      if ($payment->wasRecentlyCreated) {
          $this->gateway->charge($payment->amount, ['idempotency_key' => $reference]);
      }

      return response()->json($payment, 201);
  }
  ```

The unique `reference` column stops a second charge even after the idempotency record expires.

### 3. Multi-tenant API with API clients

```php title="app/Idempotency/ClientScope.php"
class ClientScope implements ScopeResolver
{
    public function resolve(Request $request): ?string
    {
        $client = $request->attributes->get('api_client');

        return $client === null ? null : 'client:'.$client->id;
    }
}
```

```php title="config/pharaonic/api-idempotency.php"
'scope' => App\Idempotency\ClientScope::class,
```

### 4. High-traffic endpoints on Redis

```dotenv title=".env" no-copy
IDEMPOTENCY_STORE=redis
IDEMPOTENCY_CONNECTION=idempotency
```

```php title="config/database.php"
'redis' => [
    // ...
    'idempotency' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'database' => 3,
    ],
],
```

### 5. Alert on outcomes that need a human

```php title="app/Listeners/ReportUnresolvedRequest.php"
class ReportUnresolvedRequest
{
    public function handle(IdempotentRequestUnresolved $event)
    {
        report(new RuntimeException("Idempotent request {$event->key} ended as {$event->reason}."));
    }
}
```
