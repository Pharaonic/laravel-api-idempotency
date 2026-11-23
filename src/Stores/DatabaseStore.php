<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Pharaonic\Laravel\ApiIdempotency\Support\Record;
use RuntimeException;

/**
 * Records in one table, reserved with an insert that ignores duplicates
 *
 * The primary key on storage_key makes the insert the lock: on MySQL,
 * MariaDB, PostgreSQL and SQLite exactly one concurrent insert succeeds.
 * Every other change is a single conditional UPDATE or DELETE on the owner
 * token, so a stale worker never overwrites a record it no longer holds.
 *
 * @internal
 */
final class DatabaseStore implements Store
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
    ) {}

    public function reserve(Record $record, int $now): ?Record
    {
        $row = $record->toArray();

        // A conflicting record can expire or vanish between two statements;
        // each turn either reserves, returns a live record, or sees a change.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($this->query()->insertOrIgnore($row) > 0) {
                return null;
            }

            $current = $this->query()->where('storage_key', $record->key)->first();

            if ($current === null) {
                continue;
            }

            $existing = Record::fromArray((array) $current);

            if (! $existing->isExpired($now)) {
                return $existing;
            }

            $replaced = $this->query()
                ->where('storage_key', $record->key)
                ->where('owner_token', $existing->owner)
                ->where('expires_at', '<=', $now)
                ->update(array_diff_key($row, ['storage_key' => true]));

            if ($replaced > 0) {
                return null;
            }
        }

        throw new RuntimeException('The idempotency key could not be reserved because its record kept changing.');
    }

    public function replace(string $owner, Record $record): bool
    {
        return $this->query()
            ->where('storage_key', $record->key)
            ->where('owner_token', $owner)
            ->where('state', Record::PROCESSING)
            ->update(array_diff_key($record->toArray(), ['storage_key' => true])) > 0;
    }

    public function release(string $key, string $owner): bool
    {
        return $this->query()
            ->where('storage_key', $key)
            ->where('owner_token', $owner)
            ->where('state', Record::PROCESSING)
            ->delete() > 0;
    }

    public function prune(int $now, bool $unresolved, bool $apply): int
    {
        $query = $this->query()->where('expires_at', '<=', $now);

        if (! $unresolved) {
            $query->where('state', Record::COMPLETED);
        }

        return $apply ? $query->delete() : $query->count();
    }

    public function unresolved(): int
    {
        return $this->query()->where('state', '!=', Record::COMPLETED)->count();
    }

    private function query(): Builder
    {
        return $this->connection->table($this->table);
    }
}
