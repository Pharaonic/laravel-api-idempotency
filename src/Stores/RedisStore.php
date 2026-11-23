<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Stores;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Pharaonic\Laravel\ApiIdempotency\Support\Record;
use RuntimeException;

/**
 * Records as Redis hashes, changed only by Lua scripts
 *
 * Redis runs each script atomically, and every script touches one key, so
 * the store works on a cluster too. Expiry is checked against the
 * application's clock and enforced again with PEXPIRE, so records disappear
 * by themselves and never before their TTL.
 *
 * @internal
 */
final class RedisStore implements Store
{
    private const RESERVE = <<<'LUA'
local existing = redis.call('HGETALL', KEYS[1])
if #existing > 0 then
    local expires = tonumber(redis.call('HGET', KEYS[1], 'expires_at'))
    if expires ~= nil and expires > tonumber(ARGV[1]) then
        return existing
    end
    redis.call('DEL', KEYS[1])
end
redis.call('HSET', KEYS[1], unpack(ARGV, 3))
redis.call('PEXPIRE', KEYS[1], ARGV[2])
return 1
LUA;

    private const REPLACE = <<<'LUA'
if redis.call('HGET', KEYS[1], 'owner_token') ~= ARGV[1] or redis.call('HGET', KEYS[1], 'state') ~= 'processing' then
    return 0
end
redis.call('DEL', KEYS[1])
redis.call('HSET', KEYS[1], unpack(ARGV, 3))
redis.call('PEXPIRE', KEYS[1], ARGV[2])
return 1
LUA;

    private const RELEASE = <<<'LUA'
if redis.call('HGET', KEYS[1], 'owner_token') ~= ARGV[1] or redis.call('HGET', KEYS[1], 'state') ~= 'processing' then
    return 0
end
return redis.call('DEL', KEYS[1])
LUA;

    public function __construct(
        private Connection $redis,
        private string $prefix,
    ) {
    }

    public function reserve(Record $record, int $now): ?Record
    {
        $result = $this->evaluate(self::RESERVE, $this->prefix.$record->key, [$now, ...$this->arguments($record, $now)]);

        if ($result === 1) {
            return null;
        }

        if (! is_array($result) || $result === []) {
            throw new RuntimeException('The Redis idempotency store returned an unexpected reply.');
        }

        $fields = ['storage_key' => $record->key];

        foreach (array_chunk(array_values($result), 2) as $pair) {
            if (count($pair) === 2 && is_scalar($pair[0])) {
                $fields[(string) $pair[0]] = $pair[1];
            }
        }

        return Record::fromArray($fields);
    }

    public function replace(string $owner, Record $record): bool
    {
        return $this->changed($this->evaluate(self::REPLACE, $this->prefix.$record->key, [$owner, ...$this->arguments($record, $record->completedAt ?? $record->createdAt)]));
    }

    public function release(string $key, string $owner): bool
    {
        return $this->changed($this->evaluate(self::RELEASE, $this->prefix.$key, [$owner]));
    }

    public function prune(int $now, bool $unresolved, bool $apply): ?int
    {
        return null;
    }

    public function unresolved(): ?int
    {
        return null;
    }

    /**
     * Run a script on one key with both clients, as Laravel's own eval() does:
     * phpredis takes the keys and arguments as one array, predis as a list.
     *
     * @param  list<int|string>  $arguments
     */
    private function evaluate(string $script, string $key, array $arguments): mixed
    {
        if ($this->redis instanceof PhpRedisConnection) {
            return $this->redis->command('eval', [$script, [$key, ...$arguments], 1]);
        }

        return $this->redis->command('eval', [$script, 1, $key, ...$arguments]);
    }

    /**
     * The key's lifetime in milliseconds, then field and value pairs without nulls
     *
     * @return list<int|string>
     */
    private function arguments(Record $record, int $now): array
    {
        $arguments = [max(1, $record->expiresAt - $now) * 1000];

        foreach ($record->toArray() as $field => $value) {
            if ($value !== null && $field !== 'storage_key') {
                $arguments[] = $field;
                $arguments[] = $value;
            }
        }

        return $arguments;
    }

    private function changed(mixed $result): bool
    {
        if (! is_int($result)) {
            throw new RuntimeException('The Redis idempotency store returned an unexpected reply.');
        }

        return $result > 0;
    }
}
