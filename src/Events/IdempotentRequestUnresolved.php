<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Events;

use Illuminate\Http\Request;

/**
 * Dispatched by the idempotency middleware when a request ran but its outcome could not be stored for replay
 *
 * The key stays locked until it expires, so the request never runs twice. $reason is
 * "server_error" (a 5xx response), "exception" (the route threw), "unstorable_response"
 * (a response that cannot be replayed) or "store_failed" (the store could not record the outcome).
 *
 * $key is the storage key, a SHA-256 hash: it never reveals the client's key or identity.
 */
final class IdempotentRequestUnresolved
{
    public function __construct(
        public Request $request,
        public string $key,
        public string $reason,
    ) {}
}
