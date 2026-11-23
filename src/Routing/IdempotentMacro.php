<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Routing;

use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\Route;
use LogicException;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;

/**
 * The idempotent() macro on Route and on Route::resource()/apiResource()
 *
 * It only adds the middleware with its ttl as a plain string, so the
 * route stays cacheable and keeps its fluent chain.
 *
 * @internal
 */
final class IdempotentMacro
{
    public function register(): void
    {
        $macro = function (?int $ttl = null): object {
            return IdempotentMacro::apply($this, $ttl);
        };

        Route::macro('idempotent', $macro);
        PendingResourceRegistration::macro('idempotent', $macro);
    }

    public static function apply(object $target, ?int $ttl): Route|PendingResourceRegistration
    {
        if (! $target instanceof Route && ! $target instanceof PendingResourceRegistration) {
            throw new LogicException('idempotent() can only be called on a route or a resource registration.');
        }

        $target->middleware(EnsureIdempotency::using($ttl));

        return $target;
    }
}
