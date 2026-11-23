<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Scopes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver;

/**
 * The authenticated user, by class and identifier
 *
 * Uses $request->user(), so it sees the guard an "auth:<guard>" middleware
 * selected. Run authentication before the idempotency middleware; the
 * package puts it after Laravel's authentication middleware for you.
 */
final class AuthenticatedUser implements ScopeResolver
{
    public function resolve(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_scalar($id) && $id !== '' ? get_class($user).'#'.$id : null;
    }
}
