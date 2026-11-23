<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Events;

use Illuminate\Http\Request;

/**
 * Dispatched by the idempotency middleware when it refused a request because of its key's record
 *
 * $reason is the error code: idempotency_key_conflict, idempotency_in_progress or idempotency_outcome_unknown.
 *
 * $key is the storage key, a SHA-256 hash: it never reveals the client's key or identity.
 */
final class IdempotentRequestRejected
{
    public function __construct(
        public Request $request,
        public string $key,
        public string $reason,
    ) {}
}
