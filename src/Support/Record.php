<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Support;

use UnexpectedValueException;

/**
 * One stored idempotency key: who holds it, for which request, and how it ended
 *
 * Times are Unix timestamps in seconds. Records are never changed in place;
 * every transition returns a new record.
 *
 * @internal
 */
final class Record
{
    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public string $key,
        public string $fingerprint,
        public string $state,
        public string $owner,
        public int $createdAt,
        public int $leaseExpiresAt,
        public int $expiresAt,
        public string $method,
        public string $route,
        public ?int $completedAt = null,
        public ?int $status = null,
        public array $headers = [],
        public ?string $body = null,
    ) {
    }

    /**
     * A new reservation, kept at least as long as its lease
     */
    public static function processing(string $key, string $fingerprint, string $owner, int $now, int $lease, int $ttl, string $method, string $route): self
    {
        return new self($key, $fingerprint, self::PROCESSING, $owner, $now, $now + $lease, $now + max($lease, $ttl), $method, mb_strcut($route, 0, 255, 'UTF-8'));
    }

    /**
     * The finished request with the response to replay, kept for $ttl seconds from now
     *
     * @param  array<string, list<string>>  $headers
     */
    public function complete(int $now, int $ttl, int $status, array $headers, string $body): self
    {
        return new self($this->key, $this->fingerprint, self::COMPLETED, $this->owner, $this->createdAt, $this->leaseExpiresAt, $now + $ttl, $this->method, $this->route, $now, $status, $headers, $body);
    }

    /**
     * The request whose side effects may or may not have happened, kept for $ttl seconds from now
     */
    public function unknown(int $now, int $ttl): self
    {
        return new self($this->key, $this->fingerprint, self::UNKNOWN, $this->owner, $this->createdAt, $this->leaseExpiresAt, $now + $ttl, $this->method, $this->route, $now);
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * The record as storage columns; headers are JSON
     *
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'storage_key' => $this->key,
            'request_fingerprint' => $this->fingerprint,
            'state' => $this->state,
            'owner_token' => $this->owner,
            'method' => $this->method,
            'route' => $this->route,
            'created_at' => $this->createdAt,
            'lease_expires_at' => $this->leaseExpiresAt,
            'expires_at' => $this->expiresAt,
            'completed_at' => $this->completedAt,
            'response_status' => $this->status,
            'response_headers' => $this->status === null ? null : json_encode($this->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'response_body' => $this->body,
        ];
    }

    /**
     * @param  array<mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $headers = [];

        if (is_string($row['response_headers'] ?? null) && $row['response_headers'] !== '') {
            $decoded = json_decode($row['response_headers'], true, 4, JSON_THROW_ON_ERROR);

            foreach (is_array($decoded) ? $decoded : [] as $name => $values) {
                $headers[(string) $name] = array_values(array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', is_array($values) ? $values : [$values]));
            }
        }

        return new self(
            self::string($row, 'storage_key'),
            self::string($row, 'request_fingerprint'),
            self::string($row, 'state'),
            self::string($row, 'owner_token'),
            self::int($row, 'created_at'),
            self::int($row, 'lease_expires_at'),
            self::int($row, 'expires_at'),
            self::string($row, 'method'),
            self::string($row, 'route'),
            self::nullableInt($row, 'completed_at'),
            self::nullableInt($row, 'response_status'),
            $headers,
            isset($row['response_body']) && is_string($row['response_body']) ? $row['response_body'] : null,
        );
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException("The stored idempotency record has no valid [{$column}].");
        }

        return (string) $value;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        if (! is_numeric($value)) {
            throw new UnexpectedValueException("The stored idempotency record has no valid [{$column}].");
        }

        return (int) $value;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function nullableInt(array $row, string $column): ?int
    {
        return isset($row[$column]) && $row[$column] !== '' ? self::int($row, $column) : null;
    }
}
