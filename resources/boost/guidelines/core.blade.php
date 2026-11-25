## Laravel API Idempotency (pharaonic/laravel-api-idempotency)

Opt-in HTTP idempotency for Laravel routes: `->idempotent()` reserves the client's `Idempotency-Key` atomically in the database or Redis, runs the route once, and replays its stored response to retries. Use the `api-idempotency-development` skill for the full API.

### Conventions

- Only routes marked with `->idempotent()` (or the `idempotent` middleware alias) are protected; `GET`, `HEAD`, `OPTIONS` and `TRACE` always pass through.
- Clients send one `Idempotency-Key` header of 1 to 255 visible ASCII characters, a UUID per user action, and resend the exact same request on retry.
- Keys are scoped per principal: the authenticated user by default, so idempotent routes need authentication (`auth:sanctum`, `auth:api`) or a custom `ScopeResolver`.
- Only `2xx` JSON (or empty) responses are stored; other `4xx` release the key; `5xx`, exceptions and unstorable successes lock it as an unknown outcome that never runs again by itself.
- Refusals render `{"message", "code"}`: the message follows the application locale (`api-idempotency::messages`, English and Arabic), the code never changes.
- The database store needs its published migration (`--tag=api-idempotency-migrations`); Redis needs none.
- This package prevents duplicate execution of one key; it is not exactly-once delivery. For payments, also enforce a unique business reference.

### Main features

@verbatim
<code-snippet name="Mark routes idempotent" lang="php">
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])->idempotent();
    Route::post('payments', [PaymentController::class, 'store'])->idempotent(ttl: 604800); // replay for 7 days
});
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Scope keys per tenant" lang="php">
use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver;

class TenantScope implements ScopeResolver
{
    public function resolve(Request $request): ?string
    {
        $tenant = $request->attributes->get('tenant_id');

        return $tenant === null ? null : 'tenant:'.$tenant.':user:'.optional($request->user())->getAuthIdentifier();
    }
}
// config/pharaonic/api-idempotency.php: 'scope' => App\Idempotency\TenantScope::class,
</code-snippet>
@endverbatim
