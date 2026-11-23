## Scopes

Two requests share an idempotency key only when they resolve the same scope. The storage key is a SHA-256 of the scope, the HTTP method, the route (domain and URI pattern) and the client's key, so the same key on another route, method, user or tenant is a different record.

### Authenticated user (default)

`Pharaonic\Laravel\ApiIdempotency\Scopes\AuthenticatedUser` uses `$request->user()`: the user's class and identifier. A request without a user is refused with `401 idempotency_unauthenticated`, so anonymous clients never share one key space.

### Your own resolver

Implement `Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver` for tenants, API clients or guest checkouts. Return `null` when the request has no principal.

```php title="app/Idempotency/TenantScope.php"
namespace App\Idempotency;

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
```

```php title="config/pharaonic/api-idempotency.php"
'scope' => App\Idempotency\TenantScope::class,
```

The resolver is resolved from the container on every request, so it can read request state safely under Octane.

:::warning A scope must identify one principal
Returning a constant, or an IP address shared by many users, lets one client replay another client's response. Scope by something you authenticated.
:::
