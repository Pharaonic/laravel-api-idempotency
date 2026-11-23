<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Stores;

use Pharaonic\Laravel\ApiIdempotency\Support\Record;

/**
 * Atomic storage of idempotency records
 *
 * Every write is a single atomic step: two callers can never both reserve a
 * key, and only the owner of a processing record can change or release it.
 *
 * @internal
 */
interface Store
{
    /**
     * Store a processing record unless a live record holds its key
     *
     * A record that expired at $now counts as absent and is replaced in the
     * same step. Returns null when the key is now reserved for $record, or
     * the live record that holds it.
     */
    public function reserve(Record $record, int $now): ?Record;

    /**
     * Replace the processing record that $owner holds under $record's key
     *
     * Used to finish a request and to take over an abandoned one. Returns false,
     * changing nothing, when the key no longer holds a processing record of $owner.
     */
    public function replace(string $owner, Record $record): bool;

    /**
     * Delete the processing record that $owner holds under $key
     */
    public function release(string $key, string $owner): bool;

    /**
     * Delete (or, without $apply, count) the records that expired at $now
     *
     * Only completed records unless $unresolved is set; null when the store
     * expires records by itself.
     */
    public function prune(int $now, bool $unresolved, bool $apply): ?int;

    /**
     * How many processing or unknown-outcome records are stored; null when the store cannot tell
     */
    public function unresolved(): ?int;
}
