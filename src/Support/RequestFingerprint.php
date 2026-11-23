<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Support;

use Illuminate\Http\Request;
use JsonException;
use Pharaonic\Fingerprint\Exceptions\FingerprintException;
use Pharaonic\Fingerprint\Fingerprint;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;

/**
 * Fingerprints of a request and of its idempotency key, through pharaonic/php-fingerprint
 *
 * The request fingerprint covers the method, path, query string and the
 * decoded JSON body. JSON objects compare regardless of key
 * order; lists keep their order; 1, 1.0 and "1" are different values;
 * {} and [] are different. Integers beyond PHP_INT_MAX are compared by their
 * digits. Floats are compared after PHP decodes them, so 1e2 equals 100.0.
 *
 * @internal
 */
final class RequestFingerprint
{
    private const REQUEST_NAMESPACE = 'pharaonic/laravel-api-idempotency:request';

    private const KEY_NAMESPACE = 'pharaonic/laravel-api-idempotency:key';

    /**
     * The deepest JSON body accepted, as json_decode() allows by default
     */
    private const MAX_DEPTH = 512;

    /**
     * The storage key: a SHA-256 of the scope, method, route and client key, never the raw values
     */
    public static function key(string $scope, string $method, string $route, string $clientKey): string
    {
        return Fingerprint::for([
            'scope' => $scope,
            'method' => $method,
            'route' => $route,
            'key' => $clientKey,
        ])->namespace(self::KEY_NAMESPACE)->make();
    }

    /**
     * The request's SHA-256 fingerprint, after checking that its body is JSON or empty
     *
     * @throws IdempotencyException
     */
    public static function request(Request $request): string
    {
        $content = (string) $request->getContent();
        $body = null;

        if ($content !== '') {
            if (! $request->isJson()) {
                throw IdempotencyException::unsupportedRequest();
            }

            try {
                $body = json_decode($content, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException) {
                throw IdempotencyException::invalidRequest();
            }
        }

        try {
            return Fingerprint::for([
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'query' => $request->query->all(),
                'has_body' => $content !== '',
                'body' => $body,
            ])
                ->namespace(self::REQUEST_NAMESPACE)
                ->maxDepth(self::MAX_DEPTH + 4)
                ->make();
        } catch (FingerprintException) {
            throw IdempotencyException::invalidRequest();
        }
    }
}
