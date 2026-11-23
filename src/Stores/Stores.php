<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Stores;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Builds the store configured in pharaonic.api-idempotency.store
 *
 * A store is built for each use from the current connections, so a
 * long-running worker never keeps one connection across requests.
 *
 * @internal
 */
final class Stores
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    /**
     * "database" or "redis"
     */
    public function driver(): string
    {
        $driver = $this->config->get('pharaonic.api-idempotency.store.driver');

        if ($driver !== 'database' && $driver !== 'redis') {
            throw new InvalidArgumentException('[pharaonic.api-idempotency.store.driver] must be "database" or "redis": both reserve keys atomically.');
        }

        return $driver;
    }

    public function get(): Store
    {
        $driver = $this->driver();
        $connection = $this->option('connection', true);

        if ($driver === 'database') {
            $table = $this->option('table', false);

            if ($table === null || $table === '') {
                throw new InvalidArgumentException('[pharaonic.api-idempotency.store.table] must name the table of the database store.');
            }

            /** @var ConnectionResolverInterface $db */
            $db = $this->container->make('db');
            /** @var ConnectionInterface $database */
            $database = $db->connection($connection);

            return new DatabaseStore($database, $table);
        }

        /** @var \Illuminate\Contracts\Redis\Factory $redis */
        $redis = $this->container->make('redis');

        return new RedisStore($redis->connection($connection), $this->option('prefix', true) ?? '');
    }

    private function option(string $name, bool $nullable): ?string
    {
        $value = $this->config->get('pharaonic.api-idempotency.store.'.$name);

        if (! is_string($value) && ! ($nullable && $value === null)) {
            throw new InvalidArgumentException("[pharaonic.api-idempotency.store.{$name}] must be a string".($nullable ? ' or null.' : '.'));
        }

        return $value;
    }
}
