<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The validated configuration of one idempotent request
 *
 * Built from the config repository on every request, so nothing outlives it.
 * Invalid values throw instead of being corrected.
 *
 * @internal
 */
final class Options
{
    public const MAX_TTL = 31536000;

    public const MAX_LEASE = 86400;

    public function __construct(
        public int $ttl,
        public int $lease,
        public string $scope,
        public int $maxResponseBytes,
    ) {
    }

    /**
     * The package configuration, with a route's own ttl when it sets one
     */
    public static function fromConfig(Repository $config, ?string $ttl = null): self
    {
        $get = static fn (string $key): mixed => $config->get('pharaonic.api-idempotency.'.$key);

        return new self(
            $ttl === null || $ttl === '' ? self::ttl($get('ttl'), 'pharaonic.api-idempotency.ttl') : self::ttl($ttl, 'route ttl'),
            self::range($get('lease'), 1, self::MAX_LEASE, 'lease'),
            self::scope($get('scope')),
            self::range($get('max_response_bytes'), 0, PHP_INT_MAX, 'max_response_bytes'),
        );
    }

    /**
     * A TTL in seconds, from 1 to MAX_TTL
     */
    public static function ttl(mixed $ttl, string $name): int
    {
        if (is_string($ttl) && preg_match('/\A[1-9][0-9]{0,9}\z/', $ttl) === 1) {
            $ttl = (int) $ttl;
        }

        if (! is_int($ttl) || $ttl < 1 || $ttl > self::MAX_TTL) {
            throw new InvalidArgumentException("The idempotency {$name} must be a whole number of seconds from 1 to ".self::MAX_TTL.'.');
        }

        return $ttl;
    }

    private static function range(mixed $value, int $min, int $max, string $key): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("[pharaonic.api-idempotency.{$key}] must be an integer from {$min} to {$max}.");
        }

        return $value;
    }

    private static function scope(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('[pharaonic.api-idempotency.scope] must be a non-empty string.');
        }

        return $value;
    }
}
