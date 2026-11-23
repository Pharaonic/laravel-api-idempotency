<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | ttl: seconds a finished request stays replayable, counted from the moment
    | it finished (1 to 31536000). Once it expires, the same key starts a new
    | operation. Routes may override it: ->idempotent(ttl: 600).
    |
    | lease: seconds a request may run before it counts as abandoned (1 to
    | 86400). Until then retries get 409 with Retry-After; after it, 409
    | idempotency_outcome_unknown, because its side effects may or may not
    | have happened. An abandoned request never runs again by itself.
    |
    */

    'ttl' => 86400,

    'lease' => 120,

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    | Keys are scoped per principal: a class implementing
    | Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver. The default
    | uses the authenticated user; a request without one is rejected with 401,
    | so anonymous clients never share keys. Use your own resolver for
    | tenants, API clients or guest checkouts.
    |
    */

    'scope' => Pharaonic\Laravel\ApiIdempotency\Scopes\AuthenticatedUser::class,

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Where keys are reserved, atomically in both drivers: "database" (a table,
    | published with the api-idempotency-migrations tag) or "redis" (hashes
    | under a key prefix, expiring by themselves). connection is a database
    | or Redis connection name; null uses the default one.
    |
    */

    'store' => [
        'driver' => env('IDEMPOTENCY_STORE', 'database'),
        'connection' => env('IDEMPOTENCY_CONNECTION'),
        'table' => 'idempotency_keys',
        'prefix' => 'idempotency:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Largest stored response
    |--------------------------------------------------------------------------
    |
    | A 2xx JSON (or empty) response up to this many bytes is stored and
    | replayed. A larger one keeps the key locked as an unknown outcome.
    |
    */

    'max_response_bytes' => 1048576,

];
