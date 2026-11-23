<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Http\Request;
use Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver;

final class TenantScope implements ScopeResolver
{
    public function resolve(Request $request): ?string
    {
        $tenant = $request->headers->get('X-Tenant');

        return is_string($tenant) && $tenant !== '' ? 'tenant:'.$tenant : null;
    }
}
