<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Contracts;

use Illuminate\Http\Request;

/**
 * Decides whose key space a request belongs to
 *
 * Two requests share idempotency keys only when they resolve the same scope,
 * so a scope must identify one principal (a user, a tenant, an API client).
 * Return null when the request has none; it is then rejected with 401.
 * Resolved from the container on every request, so it may depend on request state.
 */
interface ScopeResolver
{
    public function resolve(Request $request): ?string;
}
