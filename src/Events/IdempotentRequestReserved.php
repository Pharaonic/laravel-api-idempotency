<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Events;

use Illuminate\Http\Request;

/**
 * Dispatched by the idempotency middleware when a request reserved its key and is about to run the route
 *
 * $key is the storage key, a SHA-256 hash: it never reveals the client's key or identity.
 */
final class IdempotentRequestReserved
{
    public function __construct(
        public Request $request,
        public string $key,
    ) {
    }
}
